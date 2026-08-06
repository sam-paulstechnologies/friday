<?php

namespace App\Services\Miriam;

use App\Models\MiriamReminder;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Agents\TaskCaptureAgent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class MiriamSlackThoughtCaptureService
{
    public const TIMEZONE = 'Asia/Dubai';

    public function __construct(
        private readonly TaskCaptureAgent $taskCaptureAgent,
    ) {}

    public function capture(
        string $text,
        string $slackUserId,
        string $channelId,
        ?string $messageTs,
        ?string $threadTs,
        ?string $workspaceId,
        ?User $user,
    ): array {
        $text = trim($this->stripMentionNoise($text));

        if ($text === '') {
            return ['status' => 'ignored', 'text' => ''];
        }

        $dedupeKey = $this->dedupeKey($workspaceId, $channelId, $slackUserId, $messageTs, $text);
        $existingReminder = $this->existingReminder($dedupeKey, $messageTs);

        if ($existingReminder) {
            return $this->duplicateReminderResponse($existingReminder);
        }

        $existingTask = $this->existingTask($dedupeKey);

        if ($existingTask) {
            return $this->duplicateTaskResponse($existingTask);
        }

        $workspace = $this->workspaceFor($user);
        $parsed = $this->parseCapture($text, $user, $workspace);
        $expiresAt = CarbonImmutable::now('UTC')->addMinutes($this->pendingConfirmationMinutes());

        if (! $parsed['due_at']) {
            if (! $workspace) {
                return [
                    'status' => 'failed',
                    'text' => 'I captured the wording, but I could not find a writable Miriam workspace to save it.',
                ];
            }

            $task = DB::transaction(function () use ($parsed, $text, $slackUserId, $channelId, $messageTs, $threadTs, $workspaceId, $workspace, $user, $dedupeKey): Task {
                $task = $this->createTask($parsed, $text, $slackUserId, $channelId, $messageTs, $threadTs, $workspaceId, $workspace, $user, $dedupeKey);

                $this->recordTaskActivity($task, $user, 'slack_capture_received', 'Captured from Slack. No reminder was scheduled.');

                return $task;
            });

            return [
                'status' => 'task_created_no_reminder',
                'task' => $task,
                'text' => $this->capturedTaskText($task, $parsed, noReminder: true),
            ];
        }

        $reminder = DB::transaction(function () use ($parsed, $text, $slackUserId, $channelId, $messageTs, $threadTs, $workspaceId, $user, $dedupeKey, $expiresAt): MiriamReminder {
            $reminder = MiriamReminder::create([
                'user_id' => $user?->id,
                'category' => $this->categoryFor($parsed),
                'item_type' => $parsed['item_type'],
                'title' => $parsed['title'],
                'timezone' => self::TIMEZONE,
                'confidence' => $parsed['confidence'],
                'due_at' => $parsed['due_at']->utc(),
                'status' => 'awaiting_confirmation',
                'next_reminder_at' => null,
                'slack_user_id' => $slackUserId,
                'slack_channel_id' => $channelId,
                'slack_workspace_id' => $workspaceId,
                'source_message_ts' => $messageTs,
                'source_thread_ts' => $threadTs,
                'source_dedupe_key' => $dedupeKey,
                'metadata' => [
                    'source' => 'slack_thought_capture',
                    'capture_status' => 'awaiting_confirmation',
                    'confirmation_expires_at' => $expiresAt->toIso8601String(),
                    'original_text' => $text,
                    'description' => $parsed['description'],
                    'display_type' => $parsed['display_type'],
                    'task_type' => $parsed['task_type'],
                    'project_name' => $parsed['project_name'],
                    'project_id' => $parsed['project_id'],
                    'priority' => $parsed['priority'],
                    'due_label' => $parsed['due_label'],
                    'due_date' => $parsed['due_date'],
                    'due_time' => $parsed['due_time'],
                    'should_appear_today' => $parsed['should_appear_today'],
                    'classification' => $parsed['classification'],
                ],
            ]);

            $this->recordReminderEvent($reminder, 'slack_message_received', 'slack', [
                'slack_user_id' => $slackUserId,
                'slack_channel_id' => $channelId,
                'source_message_ts' => $messageTs,
            ]);
            $this->recordReminderEvent($reminder, 'capture_parsed', 'slack', [
                'display_type' => $parsed['display_type'],
                'project_name' => $parsed['project_name'],
                'priority' => $parsed['priority'],
                'due_label' => $parsed['due_label'],
                'confidence' => $parsed['confidence'],
            ]);

            return $reminder;
        });

        return [
            'status' => 'needs_confirmation',
            'reminder' => $reminder,
            'text' => $this->confirmationText($reminder),
            'blocks' => $this->confirmationBlocks($reminder),
        ];
    }

    public function confirm(MiriamReminder $reminder, string $slackUserId, bool $moveToToday = false): array
    {
        if (! $this->canSlackUserAct($reminder, $slackUserId)) {
            return ['ok' => false, 'text' => 'You cannot change that Miriam capture.'];
        }

        if ($reminder->status === 'cancelled') {
            return ['ok' => true, 'text' => 'That capture is already cancelled.'];
        }

        if ($reminder->task_id) {
            $task = $reminder->task ?: Task::query()->find($reminder->task_id);
            $this->activateReminder($reminder, $task, $moveToToday, alreadyConfirmed: true);

            return ['ok' => true, 'text' => $this->confirmedText($reminder->fresh() ?: $reminder, already: true)];
        }

        if ($this->isExpired($reminder)) {
            $reminder->forceFill([
                'status' => 'expired',
                'next_reminder_at' => null,
                'metadata' => $this->mergeMetadata($reminder, [
                    'capture_status' => 'expired',
                ]),
            ])->save();

            $this->recordReminderEvent($reminder, 'capture_expired', 'slack', ['slack_user_id' => $slackUserId]);

            return ['ok' => false, 'text' => 'That capture expired. Send it again when you are ready.'];
        }

        $workspace = $this->workspaceFor($reminder->user);

        if (! $workspace) {
            $reminder->forceFill([
                'status' => 'awaiting_confirmation',
                'next_reminder_at' => null,
                'metadata' => $this->mergeMetadata($reminder, [
                    'capture_status' => 'workspace_missing',
                ]),
            ])->save();

            $this->recordReminderEvent($reminder, 'capture_confirmation_failed', 'slack', [
                'reason' => 'workspace_missing',
                'slack_user_id' => $slackUserId,
            ]);

            return ['ok' => false, 'text' => 'I could not find a writable Miriam workspace to save that capture.'];
        }

        $task = DB::transaction(function () use ($reminder, $slackUserId, $moveToToday, $workspace): Task {
            $parsed = $this->parsedFromReminder($reminder, $workspace);
            $task = $this->createTask(
                $parsed,
                (string) ($reminder->metadata['original_text'] ?? $reminder->title),
                (string) $reminder->slack_user_id,
                (string) $reminder->slack_channel_id,
                $reminder->source_message_ts,
                $reminder->source_thread_ts,
                $reminder->slack_workspace_id,
                $workspace,
                $reminder->user,
                (string) $reminder->source_dedupe_key,
                $moveToToday,
            );

            $this->recordTaskActivity($task, $reminder->user, 'slack_capture_confirmed', 'Confirmed from Slack.');
            $this->activateReminder($reminder, $task, $moveToToday);
            $this->recordReminderEvent($reminder, $moveToToday ? 'task_moved_to_today' : 'capture_confirmed', 'slack', [
                'slack_user_id' => $slackUserId,
                'task_id' => $task->id,
            ]);

            return $task;
        });

        return ['ok' => true, 'task' => $task, 'text' => $this->confirmedText($reminder->fresh() ?: $reminder)];
    }

    public function cancel(MiriamReminder $reminder, string $slackUserId): array
    {
        if (! $this->canSlackUserAct($reminder, $slackUserId)) {
            return ['ok' => false, 'text' => 'You cannot change that Miriam capture.'];
        }

        if (! in_array($reminder->status, ['cancelled', 'done'], true)) {
            $reminder->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => CarbonImmutable::now('UTC'),
                'next_reminder_at' => null,
                'metadata' => $this->mergeMetadata($reminder, [
                    'capture_status' => 'cancelled',
                    'cancelled_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                ]),
            ])->save();

            $this->recordReminderEvent($reminder, 'capture_cancelled', 'slack', ['slack_user_id' => $slackUserId]);
        }

        return ['ok' => true, 'text' => 'Cancelled. I did not create or schedule that task.'];
    }

    public function edit(MiriamReminder $reminder, string $slackUserId): array
    {
        if (! $this->canSlackUserAct($reminder, $slackUserId)) {
            return ['ok' => false, 'text' => 'You cannot change that Miriam capture.'];
        }

        $this->recordReminderEvent($reminder, 'capture_edit_requested', 'slack', ['slack_user_id' => $slackUserId]);

        return ['ok' => true, 'text' => 'Reply in this thread with the corrected task, date, or time.'];
    }

    public function canSlackUserAct(MiriamReminder $reminder, string $slackUserId): bool
    {
        return blank($reminder->slack_user_id) || $reminder->slack_user_id === $slackUserId;
    }

    /**
     * Turn a pending capture into exactly one task.
     *
     * This is the single conversion path shared by the Slack confirm button
     * and the in-app Inbox. It short-circuits when the capture already has a
     * task, so repeated clicks and Slack retries cannot produce duplicates.
     *
     * @param  array{title?: string, description?: string, due_date?: string|null, priority?: string, project_id?: int|null, task_type?: string}  $overrides
     * @return array{ok: bool, task: Task|null, created: bool, reason?: string}
     */
    public function convertReminderToTask(
        MiriamReminder $reminder,
        ?User $actor = null,
        array $overrides = [],
        bool $moveToToday = false,
        string $source = 'inbox',
    ): array {
        if ($reminder->status === 'cancelled') {
            return ['ok' => false, 'task' => null, 'created' => false, 'reason' => 'cancelled'];
        }

        if ($reminder->task_id) {
            // Load the whole row: a partially-selected relation would reach the
            // task policy without workspace_id or assignee_id and be refused.
            $task = Task::query()->find($reminder->task_id);
            $this->activateReminder($reminder, $task, $moveToToday, alreadyConfirmed: true);

            return ['ok' => true, 'task' => $task, 'created' => false];
        }

        $workspace = $this->workspaceFor($reminder->user ?: $actor);

        if (! $workspace) {
            $this->recordReminderEvent($reminder, 'capture_confirmation_failed', $source, [
                'reason' => 'workspace_missing',
            ]);

            return ['ok' => false, 'task' => null, 'created' => false, 'reason' => 'workspace_missing'];
        }

        $task = DB::transaction(function () use ($reminder, $workspace, $actor, $overrides, $moveToToday, $source): Task {
            $parsed = $this->applyOverrides($this->parsedFromReminder($reminder, $workspace), $overrides);

            $task = $this->createTask(
                $parsed,
                (string) ($reminder->metadata['original_text'] ?? $reminder->title),
                (string) $reminder->slack_user_id,
                (string) $reminder->slack_channel_id,
                $reminder->source_message_ts,
                $reminder->source_thread_ts,
                $reminder->slack_workspace_id,
                $workspace,
                $reminder->user ?: $actor,
                (string) ($reminder->source_dedupe_key ?: 'inbox-capture-'.$reminder->id),
                $moveToToday,
            );

            $this->recordConversionProvenance($task, $reminder, $actor, $source);
            $this->recordTaskActivity(
                $task,
                $actor ?: $reminder->user,
                'capture_converted_to_task',
                'Converted from capture #'.$reminder->id.' via '.$source.'.'
            );
            $this->activateReminder($reminder, $task, $moveToToday);
            $this->recordReminderEvent($reminder, 'capture_converted', $source, [
                'task_id' => $task->id,
                'converted_by_user_id' => $actor?->id,
            ]);

            return $task;
        });

        // createTask() returns the existing row when the dedupe key already
        // resolved to a task, so trust the model rather than the code path.
        return ['ok' => true, 'task' => $task, 'created' => $task->wasRecentlyCreated];
    }

    /**
     * Corrections the operator made before confirming. Only values that are
     * present and valid are applied; nothing is invented.
     */
    private function applyOverrides(array $parsed, array $overrides): array
    {
        if (filled($overrides['title'] ?? null)) {
            $parsed['title'] = (string) $overrides['title'];
        }

        if (array_key_exists('description', $overrides) && filled($overrides['description'])) {
            $parsed['description'] = (string) $overrides['description'];
        }

        if (array_key_exists('due_date', $overrides)) {
            $parsed['due_date'] = filled($overrides['due_date']) ? (string) $overrides['due_date'] : null;
        }

        if (in_array($overrides['priority'] ?? null, Task::PRIORITIES, true)) {
            $parsed['priority'] = (string) $overrides['priority'];
        }

        if (in_array($overrides['task_type'] ?? null, Task::TYPES, true)) {
            $parsed['task_type'] = (string) $overrides['task_type'];
        }

        if (array_key_exists('project_id', $overrides)) {
            $parsed['project_id'] = $overrides['project_id'] !== null ? (int) $overrides['project_id'] : null;

            if ($parsed['project_id'] === null) {
                $parsed['project_name'] = null;
            }
        }

        return $parsed;
    }

    /** Keep the capture → task trail on the task itself, not only in events. */
    private function recordConversionProvenance(Task $task, MiriamReminder $reminder, ?User $actor, string $source): void
    {
        $task->forceFill([
            'source_metadata' => array_merge($task->source_metadata ?? [], [
                'capture_reminder_id' => $reminder->id,
                'converted_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                'converted_by_user_id' => $actor?->id,
                'converted_via' => $source,
            ]),
        ])->save();
    }

    public function parseCapture(string $text, ?User $user = null, ?Workspace $workspace = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now(self::TIMEZONE);
        try {
            $classification = $this->taskCaptureAgent->classify($text);
        } catch (Throwable) {
            $classification = $this->fallbackClassification($text);
        }
        $due = $this->extractDue($text, $now);
        $project = $this->detectProject($text, $workspace ?? $this->workspaceFor($user));
        $displayType = $this->displayType($text, $classification['detected_category'] ?? 'general_task');
        $title = $this->title($text, $due['matched_phrases'], $project['name']);
        $priority = $this->priority($text, $classification['priority'] ?? 'low');

        return [
            'title' => $title,
            'description' => trim($text),
            'display_type' => $displayType,
            'task_type' => $this->taskType($displayType),
            'item_type' => $this->itemType($displayType),
            'project_name' => $project['name'],
            'project_id' => $project['id'],
            'priority' => $priority,
            'due_at' => $due['due_at'],
            'due_date' => $due['due_date'],
            'due_time' => $due['due_time'],
            'due_label' => $due['due_label'],
            'should_appear_today' => $due['due_date'] === $now->toDateString(),
            'confidence' => $this->confidence($title, $due, $classification),
            'classification' => $classification,
        ];
    }

    private function createTask(
        array $parsed,
        string $originalText,
        string $slackUserId,
        string $channelId,
        ?string $messageTs,
        ?string $threadTs,
        ?string $workspaceId,
        Workspace $workspace,
        ?User $user,
        string $dedupeKey,
        bool $moveToToday = false,
    ): Task {
        $existing = $this->existingTask($dedupeKey);

        if ($existing) {
            return $existing;
        }

        return Task::create([
            'workspace_id' => $workspace->id,
            'project_id' => $parsed['project_id'],
            'task_type' => $parsed['task_type'],
            // The capture bucket, not the project grouping label.
            'workflow_state' => Task::WORKFLOW_INBOX,
            'title' => $parsed['title'],
            'description' => $this->taskDescription($originalText, $parsed),
            'status' => 'todo',
            'priority' => $parsed['priority'],
            'assignee_id' => $user?->id,
            'reporter_id' => $user?->id,
            'start_date' => $moveToToday ? CarbonImmutable::now(self::TIMEZONE)->toDateString() : null,
            'due_date' => $parsed['due_date'],
            'source' => 'slack',
            'source_dedupe_key' => $dedupeKey,
            'source_metadata' => [
                'slack_workspace_id' => $workspaceId,
                'slack_channel_id' => $channelId,
                'slack_user_id' => $slackUserId,
                'source_message_ts' => $messageTs,
                'source_thread_ts' => $threadTs,
                'original_text' => $originalText,
                'display_type' => $parsed['display_type'],
                'project_name' => $parsed['project_name'],
                'due_label' => $parsed['due_label'],
                'due_time' => $parsed['due_time'],
                'confidence' => $parsed['confidence'],
                'classification' => $parsed['classification'],
            ],
        ]);
    }

    private function activateReminder(MiriamReminder $reminder, ?Task $task, bool $moveToToday, bool $alreadyConfirmed = false): void
    {
        if ($moveToToday && $task && $task->start_date?->toDateString() !== CarbonImmutable::now(self::TIMEZONE)->toDateString()) {
            $task->forceFill(['start_date' => CarbonImmutable::now(self::TIMEZONE)->toDateString()])->save();
            $this->recordTaskActivity($task, $reminder->user, 'task_moved_to_today', 'Moved to Today from Slack capture.');
        }

        if (! in_array($reminder->status, ['pending', 'snoozed'], true) || ! $reminder->task_id) {
            $nextReminderAt = $reminder->due_at && $reminder->due_at->isFuture()
                ? $reminder->due_at
                : CarbonImmutable::now('UTC');

            $reminder->forceFill([
                'task_id' => $task?->id,
                'status' => 'pending',
                'reminder_attempts' => 0,
                'last_sent_at' => null,
                'next_reminder_at' => $nextReminderAt,
                'metadata' => $this->mergeMetadata($reminder, [
                    'capture_status' => 'confirmed',
                    'confirmed_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                    'task_id' => $task?->id,
                    'moved_to_today' => $moveToToday,
                ]),
            ])->save();
        }

        if (! $alreadyConfirmed) {
            $this->recordReminderEvent($reminder->fresh() ?: $reminder, 'reminder_scheduled', 'slack', [
                'task_id' => $task?->id,
                'due_at' => $reminder->due_at?->toIso8601String(),
            ]);
        }
    }

    private function parsedFromReminder(MiriamReminder $reminder, Workspace $workspace): array
    {
        $metadata = $reminder->metadata ?? [];

        return [
            'title' => $reminder->title,
            'description' => (string) ($metadata['description'] ?? $metadata['original_text'] ?? $reminder->title),
            'display_type' => (string) ($metadata['display_type'] ?? Str::headline($reminder->item_type ?: 'Task')),
            'task_type' => (string) ($metadata['task_type'] ?? $this->taskType((string) ($metadata['display_type'] ?? 'Task'))),
            'item_type' => $reminder->item_type,
            'project_name' => $metadata['project_name'] ?? null,
            'project_id' => $metadata['project_id'] ?? null,
            'priority' => (string) ($metadata['priority'] ?? 'medium'),
            'due_at' => $reminder->due_at ? CarbonImmutable::parse($reminder->due_at)->setTimezone(self::TIMEZONE) : null,
            'due_date' => (string) ($metadata['due_date'] ?? $reminder->due_at?->setTimezone(self::TIMEZONE)->toDateString()),
            'due_time' => $metadata['due_time'] ?? $reminder->due_at?->setTimezone(self::TIMEZONE)->format('H:i'),
            'due_label' => (string) ($metadata['due_label'] ?? 'scheduled'),
            'should_appear_today' => (bool) ($metadata['should_appear_today'] ?? false),
            'confidence' => (float) ($reminder->confidence ?? 1),
            'classification' => $metadata['classification'] ?? [],
        ];
    }

    private function duplicateReminderResponse(MiriamReminder $reminder): array
    {
        if ($reminder->status === 'awaiting_confirmation') {
            return [
                'status' => 'duplicate_pending',
                'reminder' => $reminder,
                'text' => $this->confirmationText($reminder),
                'blocks' => $this->confirmationBlocks($reminder),
            ];
        }

        return [
            'status' => 'duplicate',
            'reminder' => $reminder,
            'text' => $reminder->task_id
                ? 'Already captured: '.$reminder->title
                : 'Already processed: '.$reminder->title,
        ];
    }

    private function duplicateTaskResponse(Task $task): array
    {
        return [
            'status' => 'duplicate_task',
            'task' => $task,
            'text' => 'Already captured: '.$task->title,
        ];
    }

    private function confirmationText(MiriamReminder $reminder): string
    {
        $metadata = $reminder->metadata ?? [];

        return implode("\n", array_filter([
            '*Captured: '.$reminder->title.'*',
            'Type: '.($metadata['display_type'] ?? Str::headline($reminder->item_type ?: 'Reminder')),
            'Project: '.($metadata['project_name'] ?? 'None detected'),
            'Due: '.$this->displayDue($reminder),
            'Priority: '.$this->displayPriority((string) ($metadata['priority'] ?? 'medium')),
        ]));
    }

    private function confirmationBlocks(MiriamReminder $reminder): array
    {
        return [
            [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => $this->confirmationText($reminder)],
            ],
            [
                'type' => 'actions',
                'elements' => [
                    [
                        'type' => 'button',
                        'style' => 'primary',
                        'text' => ['type' => 'plain_text', 'text' => 'Confirm'],
                        'action_id' => 'miriam_capture_confirm',
                        'value' => (string) $reminder->id,
                    ],
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Edit'],
                        'action_id' => 'miriam_capture_edit',
                        'value' => (string) $reminder->id,
                    ],
                    [
                        'type' => 'button',
                        'style' => 'danger',
                        'text' => ['type' => 'plain_text', 'text' => 'Cancel'],
                        'action_id' => 'miriam_capture_cancel',
                        'value' => (string) $reminder->id,
                    ],
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Move to Today'],
                        'action_id' => 'miriam_capture_move_today',
                        'value' => (string) $reminder->id,
                    ],
                ],
            ],
        ];
    }

    private function capturedTaskText(Task $task, array $parsed, bool $noReminder): string
    {
        return implode("\n", array_filter([
            '*Captured: '.$task->title.'*',
            'Type: '.$parsed['display_type'],
            'Project: '.($parsed['project_name'] ?: 'None detected'),
            'Due: '.($parsed['due_date'] ? $this->displayDateOnly($parsed['due_date']) : 'No date'),
            'Priority: '.$this->displayPriority($task->priority),
            $noReminder ? 'No reminder was scheduled.' : null,
        ]));
    }

    private function confirmedText(MiriamReminder $reminder, bool $already = false): string
    {
        $prefix = $already ? 'Already confirmed' : 'Confirmed';

        return "{$prefix}: {$reminder->title}. Reminder scheduled for ".$this->displayDue($reminder).'.';
    }

    private function displayDue(MiriamReminder $reminder): string
    {
        return CarbonImmutable::parse($reminder->due_at)
            ->setTimezone($reminder->timezone ?: self::TIMEZONE)
            ->format('M j, g:i A');
    }

    private function displayDateOnly(string $date): string
    {
        return CarbonImmutable::parse($date, self::TIMEZONE)->format('M j');
    }

    private function displayPriority(string $priority): string
    {
        return match ($priority) {
            'urgent' => 'Urgent',
            'high' => 'High',
            'low' => 'Low',
            default => 'Normal',
        };
    }

    private function extractDue(string $text, CarbonImmutable $now): array
    {
        $lower = Str::lower($text);
        $phrases = [];
        $dueAt = null;
        $dueDate = null;
        $dueTime = null;
        $label = 'no_due_date';

        if (preg_match('/\bin\s+(\d+|one|two|three|four|five|six|seven|eight|nine|ten)\s+(minutes?|hours?|days?)\b/i', $lower, $matches)) {
            $count = $this->number($matches[1]);
            $unit = Str::startsWith($matches[2], 'minute') ? 'minutes' : (Str::startsWith($matches[2], 'hour') ? 'hours' : 'days');
            $dueAt = match ($unit) {
                'minutes' => $now->addMinutes($count),
                'hours' => $now->addHours($count),
                default => $now->addDays($count)->setTime(9, 0),
            };
            $phrases[] = $matches[0];
            $label = $unit === 'days' ? ($count === 1 ? 'tomorrow' : "in_{$count}_days") : "in_{$count}_{$unit}";
        } elseif (preg_match('/\b(tomorrow|today)\s+(morning|afternoon|evening)\b/i', $lower, $matches)) {
            $date = $matches[1] === 'tomorrow' ? $now->addDay() : $now;
            $hour = match ($matches[2]) {
                'morning' => 8,
                'afternoon' => 15,
                default => 19,
            };
            $dueAt = $this->ensureFuture($date->setTime($hour, 0), $now);
            $phrases[] = $matches[0];
            $label = $matches[1].'_'.$matches[2];
        } elseif (preg_match('/\b(tomorrow|today)\s+(?:at\s+)?(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\b/i', $lower, $matches)) {
            $date = $matches[1] === 'tomorrow' ? $now->addDay() : $now;
            $dueAt = $this->ensureFuture($this->timeOn($date, (int) $matches[2], (int) ($matches[3] ?? 0), $matches[4] ?? null), $now);
            $phrases[] = $matches[0];
            $label = $matches[1];
        } elseif (preg_match('/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\s+(?:at\s+)?(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\b/i', $lower, $matches)) {
            $date = $this->nextWeekday($now, $matches[1]);
            $dueAt = $this->timeOn($date, (int) $matches[2], (int) ($matches[3] ?? 0), $matches[4] ?? null);
            $phrases[] = $matches[0];
            $label = $matches[1];
        } elseif (preg_match('/\bat\s+(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\b/i', $lower, $matches)) {
            $dueAt = $this->ensureFuture($this->timeOn($now, (int) $matches[1], (int) ($matches[2] ?? 0), $matches[3] ?? null), $now);
            $phrases[] = $matches[0];
            $label = 'today';
        } elseif (str_contains($lower, 'tonight')) {
            $dueAt = $this->ensureFuture($now->setTime(19, 0), $now);
            $phrases[] = 'tonight';
            $label = 'tonight';
        } elseif (preg_match('/\bnext\s+week\b/i', $lower, $matches)) {
            $dueDate = $now->addWeek()->startOfWeek()->toDateString();
            $phrases[] = $matches[0];
            $label = 'next_week';
        } elseif (preg_match('/\bthis\s+week\b/i', $lower, $matches)) {
            $dueDate = $now->endOfWeek()->toDateString();
            $phrases[] = $matches[0];
            $label = 'this_week';
        } elseif (preg_match('/\b(?:on\s+)?(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', $lower, $matches)) {
            $dueDate = $this->nextWeekday($now, $matches[1])->toDateString();
            $phrases[] = $matches[0];
            $label = $matches[1];
        } elseif (preg_match('/\btomorrow\b/i', $lower, $matches)) {
            $dueDate = $now->addDay()->toDateString();
            $phrases[] = $matches[0];
            $label = 'tomorrow';
        } elseif (preg_match('/\btoday\b/i', $lower, $matches)) {
            $dueDate = $now->toDateString();
            $phrases[] = $matches[0];
            $label = 'today';
        }

        if ($dueAt) {
            $dueDate = $dueAt->toDateString();
            $dueTime = $dueAt->format('H:i');
        }

        return [
            'due_at' => $dueAt,
            'due_date' => $dueDate,
            'due_time' => $dueTime,
            'due_label' => $label,
            'matched_phrases' => $phrases,
        ];
    }

    private function title(string $text, array $matchedPhrases, ?string $projectName): string
    {
        $title = $this->stripMentionNoise($text);
        $title = preg_replace('/^(task|reminder|idea|thought|note)\s*:\s*/i', '', $title) ?? $title;
        $title = preg_replace('/^remind me\s+(?:to\s+)?/i', '', $title) ?? $title;
        $title = preg_replace('/^remember\s+(?:to\s+)?/i', '', $title) ?? $title;

        foreach ($matchedPhrases as $phrase) {
            $title = preg_replace('/\b'.preg_quote($phrase, '/').'\b/i', ' ', $title) ?? $title;
        }

        if ($projectName) {
            foreach ($this->aliasesFor($projectName) as $alias) {
                $title = preg_replace('/\s+(about|for|in)\s+'.preg_quote($alias, '/').'\.?$/i', '', $title) ?? $title;
            }
        }

        $title = trim((string) Str::of($title)
            ->replaceMatches('/\s+/', ' ')
            ->trim(' .:-'));

        return Str::of($title ?: $text)->limit(120, '')->ucfirst()->toString();
    }

    private function displayType(string $text, string $category): string
    {
        $lower = Str::lower($text);

        return match (true) {
            preg_match('/^(idea|thought)\s*:/i', trim($text)) === 1 || Str::contains($lower, ['idea:', 'thought:']) => 'Idea',
            preg_match('/^note\s*:/i', trim($text)) === 1 || Str::contains($lower, ['note for', 'create a note']) => 'Note',
            Str::contains($lower, ['follow up', 'follow-up']) => 'Follow-up',
            Str::contains($lower, ['remind me', 'reminder:', 'remember']) => 'Reminder',
            $category === 'decision' => 'Decision',
            default => 'Task',
        };
    }

    private function taskType(string $displayType): string
    {
        return match ($displayType) {
            'Follow-up' => 'follow_up',
            'Decision' => 'decision',
            default => 'task',
        };
    }

    private function itemType(string $displayType): string
    {
        return match ($displayType) {
            'Follow-up' => 'follow_up',
            'Idea' => 'idea',
            'Note' => 'note',
            default => Str::lower($displayType),
        };
    }

    private function priority(string $text, string $fallback): string
    {
        $lower = Str::lower($text);

        if (Str::contains($lower, ['urgent', 'now', 'blocked', 'production', 'payment', 'deadline'])) {
            return 'high';
        }

        if (Str::contains($lower, ['client', 'follow up', 'invoice', 'today'])) {
            return 'high';
        }

        return match ($fallback) {
            'high' => 'high',
            'low' => 'low',
            default => 'medium',
        };
    }

    private function confidence(string $title, array $due, array $classification): float
    {
        if ($title === '') {
            return 0.45;
        }

        if ($due['due_at']) {
            return 0.92;
        }

        if ($due['due_date']) {
            return 0.84;
        }

        return ($classification['detected_category'] ?? 'general_task') === 'general_task' ? 0.68 : 0.78;
    }

    private function fallbackClassification(string $text): array
    {
        return [
            'original_input' => trim($text),
            'categories' => ['general_task'],
            'detected_category' => 'general_task',
            'detected_projects' => [],
            'priority' => 'low',
            'due_label' => 'no_due_date',
            'generated_task_title' => Str::limit(trim($text) ?: 'Slack capture needs review', 120, ''),
            'suggested_next_action' => 'Review the captured Slack item and classify it inside Miriam.',
            'outputs' => [],
            'source' => 'fallback_after_classifier_failure',
        ];
    }

    private function detectProject(string $text, ?Workspace $workspace): array
    {
        $normalized = $this->normalize($text);

        foreach ($this->knownProjects() as $name => $aliases) {
            if (! collect($aliases)->contains(fn (string $alias): bool => str_contains($normalized, $this->normalize($alias)))) {
                continue;
            }

            return [
                'name' => $name,
                'id' => $this->projectIdFor($name, $aliases, $workspace),
            ];
        }

        return ['name' => null, 'id' => null];
    }

    private function projectIdFor(string $name, array $aliases, ?Workspace $workspace): ?int
    {
        if (! $workspace) {
            return null;
        }

        return Project::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($query) use ($name, $aliases): void {
                $query->where('name', $name);

                foreach ($aliases as $alias) {
                    $query->orWhere('name', $alias);
                }
            })
            ->value('id');
    }

    private function knownProjects(): array
    {
        return [
            'CF Football Academy' => ['cf football academy', 'cffa', 'cffa academy', 'football academy'],
            'SayaraForce' => ['sayaraforce', 'sayara force'],
            'ChurchForce' => ['churchforce', 'church force'],
            'Marketing Portal' => ['marketing portal'],
            'PhotographerHQ' => ['photographerhq', 'photographer hq'],
            'Miriam' => ['miriam', 'friday'],
            "Paul's Technologies" => ["paul's technologies", 'pauls technologies', 'paul technologies'],
            'Personal' => ['personal'],
            'Work' => ['work'],
            'Nikhila' => ['nikhila'],
        ];
    }

    private function aliasesFor(string $projectName): array
    {
        return $this->knownProjects()[$projectName] ?? [$projectName];
    }

    private function categoryFor(array $parsed): string
    {
        return match ($parsed['display_type']) {
            'Follow-up' => 'follow_up',
            'Idea' => 'idea',
            'Note' => 'note',
            default => $parsed['project_name'] ? 'work' : 'personal',
        };
    }

    private function taskDescription(string $originalText, array $parsed): string
    {
        $lines = [
            $parsed['description'],
            '',
            'Captured from Slack.',
            'Original wording: '.$originalText,
        ];

        if ($parsed['project_name']) {
            $lines[] = 'Detected project/client: '.$parsed['project_name'];
        }

        if ($parsed['due_time']) {
            $lines[] = 'Reminder time: '.$parsed['due_time'].' '.self::TIMEZONE;
        }

        return trim(implode("\n", array_filter($lines, fn (?string $line): bool => $line !== null)));
    }

    private function workspaceFor(?User $user): ?Workspace
    {
        if (! $user) {
            return Workspace::query()->oldest()->first();
        }

        $workspaceId = collect($user->accessibleWorkspaceIds())
            ->first(fn (int $id): bool => $user->canWriteWorkspace($id));

        if ($workspaceId) {
            return Workspace::query()->find($workspaceId);
        }

        $owned = Workspace::query()->where('created_by', $user->id)->oldest()->first();

        if ($owned) {
            return $owned;
        }

        return Workspace::query()->firstOrCreate(
            ['slug' => 'miriam-inbox-'.$user->id],
            ['name' => 'Miriam Inbox', 'created_by' => $user->id],
        );
    }

    private function existingReminder(string $dedupeKey, ?string $messageTs): ?MiriamReminder
    {
        return MiriamReminder::query()
            ->where(function ($query) use ($dedupeKey, $messageTs): void {
                $query->where('source_dedupe_key', $dedupeKey);

                if ($messageTs) {
                    $query->orWhere('source_message_ts', $messageTs);
                }
            })
            ->latest()
            ->first();
    }

    private function existingTask(string $dedupeKey): ?Task
    {
        return Task::query()->where('source_dedupe_key', $dedupeKey)->first();
    }

    private function dedupeKey(?string $workspaceId, string $channelId, string $slackUserId, ?string $messageTs, string $text): string
    {
        return 'slack_capture:'.sha1(implode('|', [
            $workspaceId ?: 'unknown-team',
            $channelId,
            $slackUserId,
            $messageTs ?: sha1($text),
        ]));
    }

    private function recordReminderEvent(MiriamReminder $reminder, string $type, ?string $channel = null, array $metadata = []): void
    {
        $reminder->events()->create([
            'event_type' => $type,
            'channel' => $channel,
            'occurred_at' => CarbonImmutable::now('UTC'),
            'metadata' => array_filter($metadata, fn ($value) => $value !== null && $value !== ''),
        ]);
    }

    private function recordTaskActivity(Task $task, ?User $user, string $action, string $description): void
    {
        $task->activities()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'description' => $description,
        ]);
    }

    private function mergeMetadata(MiriamReminder $reminder, array $overrides): array
    {
        return array_merge($reminder->metadata ?? [], $overrides);
    }

    private function isExpired(MiriamReminder $reminder): bool
    {
        $expiresAt = $reminder->metadata['confirmation_expires_at'] ?? null;

        return filled($expiresAt) && CarbonImmutable::parse($expiresAt)->lte(CarbonImmutable::now('UTC'));
    }

    private function pendingConfirmationMinutes(): int
    {
        return max(5, (int) config('services.miriam_capture.pending_confirmation_minutes', 720));
    }

    private function timeOn(CarbonImmutable $date, int $hour, int $minute, ?string $meridiem): CarbonImmutable
    {
        $meridiem = Str::lower((string) $meridiem);

        if ($meridiem === 'pm' && $hour < 12) {
            $hour += 12;
        }

        if ($meridiem === 'am' && $hour === 12) {
            $hour = 0;
        }

        if ($meridiem === '' && $hour < 8) {
            $hour += 12;
        }

        return $date->setTime($hour, $minute);
    }

    private function ensureFuture(CarbonImmutable $candidate, CarbonImmutable $now): CarbonImmutable
    {
        return $candidate->lte($now) ? $candidate->addDay() : $candidate;
    }

    private function nextWeekday(CarbonImmutable $now, string $weekday): CarbonImmutable
    {
        return $now->next($weekday)->startOfDay();
    }

    private function number(string $value): int
    {
        return match (Str::lower($value)) {
            'one' => 1,
            'two' => 2,
            'three' => 3,
            'four' => 4,
            'five' => 5,
            'six' => 6,
            'seven' => 7,
            'eight' => 8,
            'nine' => 9,
            'ten' => 10,
            default => max(1, (int) $value),
        };
    }

    private function stripMentionNoise(string $text): string
    {
        return trim((string) Str::of($text)
            ->replaceMatches('/<@[A-Z0-9]+>/i', '')
            ->replaceMatches('/^@miriam[:,]?\s*/i', '')
            ->replaceMatches('/^miriam[:,]?\s*/i', '')
            ->replaceMatches('/\s+/', ' '));
    }

    private function normalize(string $text): string
    {
        return Str::of($text)
            ->lower()
            ->replace(['-', '_', '’', 'â€™'], [' ', ' ', "'", "'"])
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }
}
