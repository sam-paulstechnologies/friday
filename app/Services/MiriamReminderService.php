<?php

namespace App\Services;

use App\Models\MiriamReminder;
use App\Models\MiriamSlackClarification;
use App\Models\Task;
use App\Models\User;
use App\Services\Calendar\CalendarSyncService;
use App\Services\Miriam\MiriamBrainService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MiriamReminderService
{
    public const DEFAULT_TIMEZONE = 'Asia/Dubai';
    private const HIGH_CONFIDENCE = 0.75;

    public function __construct(
        private readonly MiriamBrainService $brain,
        private readonly CalendarSyncService $calendarSyncService,
    ) {}

    public function parse(string $text, ?CarbonImmutable $now = null): ?array
    {
        $now ??= CarbonImmutable::now(self::DEFAULT_TIMEZONE);
        $normalized = $this->normalizeReminderText($text);

        if (! str_starts_with($normalized, 'remind me ')) {
            return null;
        }

        if (preg_match('/^remind me in (\d+) minutes? to (.+)$/i', $normalized, $matches)) {
            $minutes = max(1, (int) $matches[1]);
            $title = $this->cleanTitle($matches[2]);

            return [
                'title' => $title,
                'due_at' => $now->addMinutes($minutes),
                'timezone' => self::DEFAULT_TIMEZONE,
                'category' => $this->categoryFor($title),
            ];
        }

        if (preg_match('/^remind me to (.+?) in (\d+) minutes?$/i', $normalized, $matches)) {
            $title = $this->cleanTitle($matches[1]);
            $minutes = max(1, (int) $matches[2]);

            return [
                'title' => $title,
                'due_at' => $now->addMinutes($minutes),
                'timezone' => self::DEFAULT_TIMEZONE,
                'category' => $this->categoryFor($title),
            ];
        }

        if (preg_match('/^remind me to (.+?) at (.+?) today$/i', $normalized, $matches)) {
            $title = $this->cleanTitle($matches[1]);
            $dueAt = $this->parseTimeOnDate($matches[2], $now);

            return [
                'title' => $title,
                'due_at' => $dueAt,
                'timezone' => self::DEFAULT_TIMEZONE,
                'category' => $this->categoryFor($title),
            ];
        }

        if (preg_match('/^remind me to (.+?) tomorrow (.+)$/i', $normalized, $matches)) {
            $title = $this->cleanTitle($matches[1]);
            $dueAt = $this->parseTimeOnDate($matches[2], $now->addDay());

            return [
                'title' => $title,
                'due_at' => $dueAt,
                'timezone' => self::DEFAULT_TIMEZONE,
                'category' => $this->categoryFor($title),
            ];
        }

        return null;
    }

    public function captureFromSlack(string $text, string $slackUserId, string $channelId, ?string $messageTs = null, ?User $user = null): ?MiriamReminder
    {
        $parsed = $this->parse($text);

        if (! $parsed) {
            return null;
        }

        if ($messageTs) {
            $existing = MiriamReminder::query()->where('source_message_ts', $messageTs)->first();

            if ($existing) {
                return $existing;
            }
        }

        return $this->createFromParsedItem($parsed + [
            'type' => 'reminder',
            'source' => 'slack',
            'original_text' => $text,
            'confidence' => 1.0,
        ], $slackUserId, $channelId, $messageTs, $user);
    }

    public function captureSmartFromSlack(string $text, string $slackUserId, string $channelId, ?string $messageTs = null, ?User $user = null): array
    {
        $resolved = $this->resolvePendingClarification($text, $slackUserId, $channelId, $messageTs, $user);

        if ($resolved !== null) {
            return $resolved;
        }

        $capture = $this->brain->interpretSlackCapture($text, $user);
        $items = $capture['items'] ?? [];

        if ($items === []) {
            return ['status' => 'failed', 'items' => [], 'reminders' => collect(), 'source' => $capture['source'] ?? null];
        }

        if (($capture['status'] ?? null) === 'needs_confirmation'
            || collect($items)->contains(fn (array $item): bool => (float) $item['confidence'] < self::HIGH_CONFIDENCE || blank($item['title'] ?? null) || ! ($item['due_at'] ?? null))) {
            $clarification = $this->createPendingClarification($items[0], $text, $slackUserId, $channelId, $messageTs, $user);

            return [
                'status' => 'needs_confirmation',
                'items' => $items,
                'reminders' => collect(),
                'clarification' => $clarification,
                'source' => $capture['source'] ?? null,
            ];
        }

        $reminders = collect($items)
            ->map(function (array $item, int $index) use ($slackUserId, $channelId, $messageTs, $user, $capture): MiriamReminder {
                $reminder = $this->createFromParsedItem(
                    $item,
                    $slackUserId,
                    $channelId,
                    $messageTs ? "{$messageTs}:{$index}" : null,
                    $user,
                );

                foreach ($capture['ai_events'] ?? [] as $event) {
                    $this->recordEvent($reminder, $event['event_type'], $event['channel'] ?? null, $event['metadata'] ?? []);
                }

                if (($item['source'] ?? null) === 'slack_ai') {
                    $this->recordEvent($reminder, 'reminder_created_from_ai', 'slack', [
                        'confidence' => $item['confidence'] ?? null,
                    ]);
                }

                return $reminder;
            });

        return ['status' => 'created', 'items' => $items, 'reminders' => $reminders, 'source' => $capture['source'] ?? null];
    }

    public function sendConfirmation(MiriamReminder $reminder): array
    {
        return $this->sendSlack(
            $reminder->slack_channel_id,
            sprintf(
                'Saved reminder: %s at %s.',
                $reminder->title,
                $reminder->due_at->setTimezone($reminder->timezone)->format('M j, g:i A')
            )
        );
    }

    public function sendCaptureSummary(string $channel, Collection $reminders): array
    {
        $lines = $reminders->values()->map(function (MiriamReminder $reminder, int $index): string {
            $when = $this->summaryTime($reminder);

            return ($index + 1).". {$when} — {$reminder->title}";
        })->implode("\n");

        return $this->sendSlack($channel, "Captured {$reminders->count()} ".Str::plural('item', $reminders->count()).":\n{$lines}");
    }

    public function sendClarification(string $channel, array $items): array
    {
        $clarification = collect($items)
            ->pluck('clarification')
            ->filter()
            ->first();

        return $this->sendSlack(
            $channel,
            $clarification ?: 'I found '.count($items).' possible '.Str::plural('task', count($items)).'. Should I save them?'
        );
    }

    private function createPendingClarification(array $item, string $text, string $slackUserId, string $channelId, ?string $messageTs, ?User $user): MiriamSlackClarification
    {
        $question = $item['clarification'] ?? 'I found a possible task. What time should I use?';

        $clarification = $messageTs
            ? MiriamSlackClarification::query()->where('source_message_ts', $messageTs)->first()
            : null;

        if ($clarification) {
            return $clarification;
        }

        return MiriamSlackClarification::create([
            'user_id' => $user?->id,
            'slack_user_id' => $slackUserId,
            'slack_channel_id' => $channelId,
            'source_message_ts' => $messageTs,
            'original_text' => $text,
            'clarification_question' => $question,
            'status' => 'pending',
            'payload' => [
                'item' => $item,
                'events' => [[
                    'event_type' => 'clarification_created',
                    'channel' => 'slack',
                    'metadata' => ['question' => $question],
                ]],
            ],
            'expires_at' => CarbonImmutable::now('UTC')->addHours(12),
        ]);
    }

    private function resolvePendingClarification(string $text, string $slackUserId, string $channelId, ?string $messageTs, ?User $user): ?array
    {
        $answer = $this->normalizeClarificationAnswer($text);

        if (! in_array($answer, ['am', 'pm'], true)) {
            return null;
        }

        /** @var MiriamSlackClarification|null $clarification */
        $clarification = MiriamSlackClarification::query()
            ->where('slack_user_id', $slackUserId)
            ->where('slack_channel_id', $channelId)
            ->where('status', 'pending')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', CarbonImmutable::now('UTC'));
            })
            ->latest()
            ->first();

        if (! $clarification) {
            return null;
        }

        $original = (string) ($clarification->payload['item']['original_text'] ?? $clarification->original_text);
        $resolvedText = preg_replace('/\btomorrow\s+(?:at\s+)?(\d{1,2})(?::(\d{2}))?\b/i', 'tomorrow at $1'.($answer === 'am' ? 'am' : 'pm'), $original, 1);
        $capture = $this->brain->interpretSlackCapture($resolvedText ?: $original, $user);
        $item = $capture['items'][0] ?? null;

        if (! $item || blank($item['title'] ?? null) || ! ($item['due_at'] ?? null) || (float) ($item['confidence'] ?? 0) < self::HIGH_CONFIDENCE) {
            return null;
        }

        $reminder = $this->createFromParsedItem(
            $item + ['original_text' => $original],
            $slackUserId,
            $channelId,
            $messageTs,
            $user,
        );

        foreach ($clarification->payload['events'] ?? [] as $event) {
            $this->recordEvent($reminder, $event['event_type'], $event['channel'] ?? null, $event['metadata'] ?? []);
        }

        $clarification->forceFill([
            'status' => 'resolved',
            'resolved_reminder_id' => $reminder->id,
            'resolved_at' => CarbonImmutable::now('UTC'),
        ])->save();

        $this->recordEvent($reminder, 'clarification_resolved', 'slack', [
            'clarification_id' => $clarification->id,
            'answer' => $answer,
        ]);

        return [
            'status' => 'created',
            'items' => [$item],
            'reminders' => collect([$reminder->fresh() ?: $reminder]),
            'source' => 'clarification',
        ];
    }

    private function normalizeClarificationAnswer(string $text): string
    {
        $value = Str::of($text)
            ->lower()
            ->replaceMatches('/<@[a-z0-9]+>/i', '')
            ->replaceMatches('/^@miriam[:,]?\s*/i', '')
            ->replaceMatches('/^miriam[:,]?\s*/i', '')
            ->trim()
            ->toString();

        return match (true) {
            in_array($value, ['am', 'a.m.', 'morning'], true) => 'am',
            in_array($value, ['pm', 'p.m.', 'evening', 'night'], true) => 'pm',
            default => $value,
        };
    }

    public function sendParseHelp(string $channel): array
    {
        return $this->sendSlack(
            $channel,
            'I can save reminders like: Remind me to call Jasion in 5 minutes.'
        );
    }

    public function sendSlackMessage(?string $channel, string $text, array $blocks = []): array
    {
        return $this->sendSlack($channel, $text, $blocks);
    }

    public function sendDueReminders(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        $sent = 0;

        MiriamReminder::query()
            ->with(['task.project'])
            ->whereIn('status', ['pending', 'snoozed'])
            ->whereNotNull('next_reminder_at')
            ->where('next_reminder_at', '<=', $now->utc())
            ->orderBy('next_reminder_at')
            ->get()
            ->each(function (MiriamReminder $reminder) use ($now, &$sent): void {
                $reminder = $reminder->fresh(['task.project']) ?: $reminder;

                if (! $this->reminderCanNotify($reminder)) {
                    return;
                }

                $dueForAttempt = $reminder->next_reminder_at;

                if ($reminder->last_sent_at && $dueForAttempt && $reminder->last_sent_at->greaterThanOrEqualTo($dueForAttempt)) {
                    $this->recordEvent($reminder, 'reminder_deduplicated', 'slack', [
                        'last_sent_at' => $reminder->last_sent_at?->toIso8601String(),
                        'next_reminder_at' => $dueForAttempt?->toIso8601String(),
                    ]);

                    return;
                }

                if ($reminder->reminder_attempts >= $this->maxPokes()) {
                    $this->exhaustReminder($reminder, $now, 'max_pokes_already_reached');

                    return;
                }

                $attempt = $reminder->reminder_attempts + 1;
                $result = $this->sendSlack(
                    $reminder->slack_channel_id,
                    "Reminder: {$reminder->title}",
                    $this->dueReminderBlocks($reminder, $attempt)
                );

                $nextReminderAt = $attempt >= $this->maxPokes()
                    ? null
                    : $this->nextPokerAt($reminder, $attempt, $now);

                $reminder->forceFill([
                    'status' => $attempt >= $this->maxPokes() ? 'exhausted' : 'pending',
                    'reminder_attempts' => $attempt,
                    'last_sent_at' => $now->utc(),
                    'next_reminder_at' => $nextReminderAt,
                    'metadata' => array_merge($reminder->metadata ?? [], [
                        'last_poke_attempt' => $attempt,
                        'last_poke_at' => $now->utc()->toIso8601String(),
                        'reminder_status' => $attempt >= $this->maxPokes() ? 'exhausted' : 'active',
                    ]),
                ])->save();

                $this->recordEvent($reminder, ($result['ok'] ?? false) ? 'slack_reminder_sent' : 'slack_reminder_failed', 'slack', [
                    'attempt' => $attempt,
                    'slack_error' => $result['error'] ?? null,
                ]);

                if ($attempt >= $this->maxPokes()) {
                    $this->recordEvent($reminder->fresh() ?: $reminder, 'reminder_escalation_exhausted', 'slack', [
                        'attempt' => $attempt,
                        'task_id' => $reminder->task_id,
                    ]);
                }

                $sent++;
            });

        return $sent;
    }

    public function syncAfterTaskSaved(Task $task, ?User $user = null, bool $rescheduleFromDueDate = false): void
    {
        $reminders = MiriamReminder::query()
            ->where('task_id', $task->id)
            ->whereIn('status', ['awaiting_confirmation', 'pending', 'snoozed', 'exhausted'])
            ->get();

        foreach ($reminders as $reminder) {
            if ($task->status === 'completed') {
                if ($reminder->status !== 'done') {
                    $reminder->forceFill([
                        'status' => 'done',
                        'completed_at' => $task->completed_at ?: CarbonImmutable::now('UTC'),
                        'next_reminder_at' => null,
                        'metadata' => array_merge($reminder->metadata ?? [], [
                            'reminder_status' => 'cancelled_by_task_completion',
                        ]),
                    ])->save();

                    $this->recordEvent($reminder, 'task_completed_inside_miriam', 'miriam', [
                        'task_id' => $task->id,
                        'user_id' => $user?->id,
                    ]);
                    $this->recordEvent($reminder, 'future_reminders_cancelled', 'miriam', [
                        'reason' => 'task_completed',
                    ]);
                }

                continue;
            }

            if ($task->status === 'archived') {
                if (! in_array($reminder->status, ['cancelled', 'done'], true)) {
                    $reminder->forceFill([
                        'status' => 'cancelled',
                        'cancelled_at' => CarbonImmutable::now('UTC'),
                        'next_reminder_at' => null,
                        'metadata' => array_merge($reminder->metadata ?? [], [
                            'reminder_status' => 'cancelled_by_task_archive',
                        ]),
                    ])->save();

                    $this->recordEvent($reminder, 'future_reminders_cancelled', 'miriam', [
                        'reason' => 'task_archived',
                    ]);
                }

                continue;
            }

            if ($rescheduleFromDueDate && $task->due_date && $reminder->due_at && in_array($reminder->status, ['pending', 'snoozed', 'exhausted'], true)) {
                $currentLocal = $reminder->due_at->setTimezone($reminder->timezone ?: self::DEFAULT_TIMEZONE);
                $newDueAt = CarbonImmutable::parse($task->due_date->toDateString().' '.$currentLocal->format('H:i:s'), $reminder->timezone ?: self::DEFAULT_TIMEZONE);

                $reminder->forceFill([
                    'status' => 'pending',
                    'due_at' => $newDueAt->utc(),
                    'next_reminder_at' => $newDueAt->isFuture() ? $newDueAt->utc() : CarbonImmutable::now('UTC'),
                    'reminder_attempts' => 0,
                    'last_sent_at' => null,
                    'metadata' => array_merge($reminder->metadata ?? [], [
                        'due_date' => $newDueAt->toDateString(),
                        'due_time' => $newDueAt->format('H:i'),
                        'reminder_status' => 'rescheduled_from_task',
                    ]),
                ])->save();

                $this->recordEvent($reminder, 'reminder_rescheduled', 'miriam', [
                    'task_id' => $task->id,
                    'due_at' => $newDueAt->utc()->toIso8601String(),
                ]);
            }
        }
    }

    private function reminderCanNotify(MiriamReminder $reminder): bool
    {
        if (! in_array($reminder->status, ['pending', 'snoozed'], true)) {
            return false;
        }

        if (! $reminder->task_id) {
            return true;
        }

        $task = $reminder->task;

        if (! $task) {
            $reminder->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => CarbonImmutable::now('UTC'),
                'next_reminder_at' => null,
            ])->save();

            $this->recordEvent($reminder, 'future_reminders_cancelled', 'miriam', [
                'reason' => 'task_missing',
            ]);

            return false;
        }

        if ($task->status === 'completed') {
            $this->syncAfterTaskSaved($task);

            return false;
        }

        if ($task->status === 'archived') {
            $this->syncAfterTaskSaved($task);

            return false;
        }

        return true;
    }

    private function nextPokerAt(MiriamReminder $reminder, int $attempt, CarbonImmutable $now): CarbonImmutable
    {
        $base = $reminder->due_at
            ? CarbonImmutable::parse($reminder->due_at)->utc()
            : $now->utc();

        $candidate = match ($attempt) {
            1 => $base->addMinutes($this->secondPokeMinutes()),
            2 => $base->addMinutes($this->finalPokeMinutes()),
            default => $now->utc()->addMinutes($this->secondPokeMinutes()),
        };

        return $candidate->lte($now->utc()) ? $now->utc()->addMinute() : $candidate;
    }

    private function exhaustReminder(MiriamReminder $reminder, CarbonImmutable $now, string $reason): void
    {
        $reminder->forceFill([
            'status' => 'exhausted',
            'next_reminder_at' => null,
            'metadata' => array_merge($reminder->metadata ?? [], [
                'reminder_status' => 'exhausted',
                'exhausted_at' => $now->utc()->toIso8601String(),
            ]),
        ])->save();

        $this->recordEvent($reminder, 'reminder_escalation_exhausted', 'slack', [
            'reason' => $reason,
            'task_id' => $reminder->task_id,
        ]);
    }

    private function secondPokeMinutes(): int
    {
        return max(1, (int) config('services.miriam_capture.second_poke_minutes', 30));
    }

    private function finalPokeMinutes(): int
    {
        return max($this->secondPokeMinutes() + 1, (int) config('services.miriam_capture.final_poke_minutes', 120));
    }

    private function maxPokes(): int
    {
        return max(1, (int) config('services.miriam_capture.max_pokes', 3));
    }

    public function markDone(MiriamReminder $reminder, string $slackUserId): MiriamReminder
    {
        if ($reminder->status !== 'done') {
            $reminder->forceFill([
                'status' => 'done',
                'completed_at' => CarbonImmutable::now('UTC'),
                'next_reminder_at' => null,
            ])->save();
        }

        if ($reminder->task_id && $reminder->task && $reminder->task->status !== 'completed') {
            $reminder->task->forceFill([
                'status' => 'completed',
                'completed_at' => CarbonImmutable::now('UTC'),
            ])->save();

            $reminder->task->activities()->create([
                'user_id' => $reminder->user_id,
                'action' => 'task_completed_from_slack',
                'description' => 'Completed from Slack reminder.',
            ]);
        }

        $this->recordEvent($reminder, 'done_clicked', 'slack', ['slack_user_id' => $slackUserId]);
        $this->recordEvent($reminder, 'future_reminders_cancelled', 'slack', ['reason' => 'done_clicked']);

        return $reminder;
    }

    public function snooze(MiriamReminder $reminder, string $slackUserId, int $minutes = 15): MiriamReminder
    {
        $target = CarbonImmutable::now('UTC')->addMinutes($minutes);

        // A redelivered Slack interaction would otherwise snooze the same
        // occurrence a second time and record a second event. One click, one
        // next occurrence.
        if ($reminder->status === 'snoozed'
            && $reminder->next_reminder_at
            && $reminder->next_reminder_at->equalTo($target)) {
            return $reminder;
        }

        if (! in_array($reminder->status, ['done', 'cancelled'], true)) {
            $nextReminderAt = $target;

            $reminder->forceFill([
                'status' => 'snoozed',
                'due_at' => $nextReminderAt,
                'next_reminder_at' => $nextReminderAt,
                'reminder_attempts' => 0,
                'last_sent_at' => null,
                'metadata' => array_merge($reminder->metadata ?? [], [
                    'reminder_status' => 'snoozed',
                    'last_snoozed_minutes' => $minutes,
                    'due_date' => $nextReminderAt->setTimezone($reminder->timezone ?: self::DEFAULT_TIMEZONE)->toDateString(),
                    'due_time' => $nextReminderAt->setTimezone($reminder->timezone ?: self::DEFAULT_TIMEZONE)->format('H:i'),
                ]),
            ])->save();
        }

        $this->recordEvent($reminder, 'reminder_snoozed', 'slack', [
            'slack_user_id' => $slackUserId,
            'minutes' => $minutes,
        ]);

        return $reminder;
    }

    public function cancel(MiriamReminder $reminder, string $slackUserId): MiriamReminder
    {
        if ($reminder->status !== 'cancelled') {
            $reminder->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => CarbonImmutable::now('UTC'),
                'next_reminder_at' => null,
            ])->save();
        }

        $this->recordEvent($reminder, 'cancel_clicked', 'slack', ['slack_user_id' => $slackUserId]);
        $this->recordEvent($reminder, 'future_reminders_cancelled', 'slack', ['reason' => 'cancel_clicked']);

        return $reminder;
    }

    public function rescheduleTonight(MiriamReminder $reminder, string $slackUserId): MiriamReminder
    {
        $localNow = CarbonImmutable::now($reminder->timezone ?: self::DEFAULT_TIMEZONE);
        $target = $localNow->setTime(19, 0);

        if ($target->lte($localNow)) {
            $target = $target->addDay();
        }

        return $this->reschedule($reminder, $target, $slackUserId, 'tonight');
    }

    public function rescheduleTomorrow(MiriamReminder $reminder, string $slackUserId): MiriamReminder
    {
        $timezone = $reminder->timezone ?: self::DEFAULT_TIMEZONE;
        $localDue = $reminder->due_at
            ? $reminder->due_at->setTimezone($timezone)
            : CarbonImmutable::now($timezone)->setTime(9, 0);

        $target = CarbonImmutable::now($timezone)
            ->addDay()
            ->setTime((int) $localDue->format('H') ?: 9, (int) $localDue->format('i'));

        return $this->reschedule($reminder, $target, $slackUserId, 'tomorrow');
    }

    public function moveToToday(MiriamReminder $reminder, string $slackUserId): MiriamReminder
    {
        if ($reminder->task_id && $reminder->task) {
            $today = CarbonImmutable::now($reminder->timezone ?: self::DEFAULT_TIMEZONE)->toDateString();

            if ($reminder->task->start_date?->toDateString() !== $today) {
                $reminder->task->forceFill(['start_date' => $today])->save();
                $reminder->task->activities()->create([
                    'user_id' => $reminder->user_id,
                    'action' => 'task_moved_to_today',
                    'description' => 'Moved to Today from Slack reminder.',
                ]);
            }
        }

        $this->recordEvent($reminder, 'task_moved_to_today', 'slack', ['slack_user_id' => $slackUserId]);

        return $reminder;
    }

    private function reschedule(MiriamReminder $reminder, CarbonImmutable $target, string $slackUserId, string $label): MiriamReminder
    {
        if (! in_array($reminder->status, ['done', 'cancelled'], true)) {
            $reminder->forceFill([
                'status' => 'pending',
                'due_at' => $target->utc(),
                'next_reminder_at' => $target->utc(),
                'reminder_attempts' => 0,
                'last_sent_at' => null,
                'metadata' => array_merge($reminder->metadata ?? [], [
                    'reminder_status' => 'rescheduled',
                    'due_date' => $target->toDateString(),
                    'due_time' => $target->format('H:i'),
                    'reschedule_label' => $label,
                ]),
            ])->save();

            if ($reminder->task_id && $reminder->task) {
                $reminder->task->forceFill(['due_date' => $target->toDateString()])->save();
                $reminder->task->activities()->create([
                    'user_id' => $reminder->user_id,
                    'action' => 'reminder_rescheduled_from_slack',
                    'description' => "Reminder rescheduled to {$target->format('M j, g:i A')}.",
                ]);
            }
        }

        $this->recordEvent($reminder, 'reminder_rescheduled', 'slack', [
            'slack_user_id' => $slackUserId,
            'label' => $label,
            'due_at' => $target->utc()->toIso8601String(),
        ]);

        return $reminder;
    }

    public function handleSlackAction(MiriamReminder $reminder, string $action, string $slackUserId, array $payload = []): string
    {
        $message = match ($action) {
            'miriam_reminder_done' => $this->handleDoneAction($reminder, $slackUserId),
            'miriam_reminder_snooze_15' => $this->handleSnoozeAction($reminder, $slackUserId),
            'miriam_reminder_snooze_60' => $this->handleSnoozeAction($reminder, $slackUserId, 60),
            'miriam_reminder_tonight' => $this->handleTonightAction($reminder, $slackUserId),
            'miriam_reminder_tomorrow' => $this->handleTomorrowAction($reminder, $slackUserId),
            'miriam_reminder_move_today' => $this->handleMoveToTodayAction($reminder, $slackUserId),
            'miriam_reminder_cancel' => $this->handleCancelAction($reminder, $slackUserId),
            default => 'Unknown Miriam reminder action.',
        };

        if ($message !== 'Unknown Miriam reminder action.') {
            $this->updateSlackActionMessage($payload, $message);
        }

        return $message;
    }

    public function recordEvent(MiriamReminder $reminder, string $type, ?string $channel = null, array $metadata = []): void
    {
        $reminder->events()->create([
            'event_type' => $type,
            'channel' => $channel,
            'occurred_at' => CarbonImmutable::now('UTC'),
            'metadata' => array_filter($metadata, fn ($value) => $value !== null && $value !== ''),
        ]);
    }

    private function createFromParsedItem(array $item, string $slackUserId, string $channelId, ?string $messageTs, ?User $user): MiriamReminder
    {
        if (blank($item['title'] ?? null) || blank($item['due_at'] ?? null)) {
            throw new \InvalidArgumentException('Miriam reminders require a non-empty title and due_at.');
        }

        $dueAt = $item['due_at'] instanceof CarbonImmutable
            ? $item['due_at']
            : CarbonImmutable::parse($item['due_at'], $item['timezone'] ?? self::DEFAULT_TIMEZONE);

        $reminder = MiriamReminder::create([
            'user_id' => $user?->id,
            'category' => $this->categoryFor($item['title']),
            'item_type' => $item['type'] ?? 'reminder',
            'title' => $item['title'],
            'timezone' => $item['timezone'] ?? self::DEFAULT_TIMEZONE,
            'confidence' => $item['confidence'] ?? 1,
            'due_at' => $dueAt->utc(),
            'status' => 'pending',
            'next_reminder_at' => $dueAt->utc(),
            'slack_user_id' => $slackUserId,
            'slack_channel_id' => $channelId,
            'source_message_ts' => $messageTs,
            'metadata' => [
                'source' => $item['source'] ?? 'slack',
                'original_text' => $item['original_text'] ?? null,
                'description' => $item['description'] ?? null,
                'risk_level' => $item['risk_level'] ?? null,
                'ai_payload' => $item['ai_payload'] ?? null,
            ],
        ]);

        $this->recordEvent($reminder, 'captured', 'slack', [
            'slack_user_id' => $slackUserId,
            'source_message_ts' => $messageTs,
            'item_type' => $reminder->item_type,
            'confidence' => $reminder->confidence,
        ]);

        $this->calendarSyncService->syncMiriamReminder($reminder);

        return $reminder->fresh() ?: $reminder;
    }

    private function summaryTime(MiriamReminder $reminder): string
    {
        $local = $reminder->due_at->setTimezone($reminder->timezone);
        $today = CarbonImmutable::now($reminder->timezone)->toDateString();

        if ($local->toDateString() === $today && $local->format('H:i') === '21:00') {
            return 'Tonight '.$local->format('g:i A');
        }

        if ($local->toDateString() === CarbonImmutable::now($reminder->timezone)->addDay()->toDateString()) {
            return 'Tomorrow '.$local->format('g:i A');
        }

        return $local->format('M j, g:i A');
    }

    private function handleDoneAction(MiriamReminder $reminder, string $slackUserId): string
    {
        if ($reminder->status === 'cancelled') {
            return $this->actionStatusMessage($reminder->fresh() ?: $reminder);
        }

        if ($reminder->status !== 'done') {
            $this->markDone($reminder, $slackUserId);
        }

        return $this->actionStatusMessage($reminder->fresh() ?: $reminder);
    }

    private function handleSnoozeAction(MiriamReminder $reminder, string $slackUserId, int $minutes = 15): string
    {
        if (in_array($reminder->status, ['done', 'cancelled'], true)) {
            return $this->actionStatusMessage($reminder->fresh() ?: $reminder);
        }

        $this->snooze($reminder, $slackUserId, $minutes);

        return $this->actionStatusMessage($reminder->fresh() ?: $reminder);
    }

    private function handleTonightAction(MiriamReminder $reminder, string $slackUserId): string
    {
        if (in_array($reminder->status, ['done', 'cancelled'], true)) {
            return $this->actionStatusMessage($reminder->fresh() ?: $reminder);
        }

        $this->rescheduleTonight($reminder, $slackUserId);

        return $this->actionStatusMessage($reminder->fresh() ?: $reminder);
    }

    private function handleTomorrowAction(MiriamReminder $reminder, string $slackUserId): string
    {
        if (in_array($reminder->status, ['done', 'cancelled'], true)) {
            return $this->actionStatusMessage($reminder->fresh() ?: $reminder);
        }

        $this->rescheduleTomorrow($reminder, $slackUserId);

        return $this->actionStatusMessage($reminder->fresh() ?: $reminder);
    }

    private function handleMoveToTodayAction(MiriamReminder $reminder, string $slackUserId): string
    {
        if (in_array($reminder->status, ['done', 'cancelled'], true)) {
            return $this->actionStatusMessage($reminder->fresh() ?: $reminder);
        }

        $this->moveToToday($reminder, $slackUserId);

        return 'Moved to Today - '.$reminder->title;
    }

    private function handleCancelAction(MiriamReminder $reminder, string $slackUserId): string
    {
        if ($reminder->status === 'done') {
            return $this->actionStatusMessage($reminder->fresh() ?: $reminder);
        }

        if ($reminder->status !== 'cancelled') {
            $this->cancel($reminder, $slackUserId);
        }

        return $this->actionStatusMessage($reminder->fresh() ?: $reminder);
    }

    private function actionStatusMessage(MiriamReminder $reminder): string
    {
        return match ($reminder->status) {
            'done' => "Done - {$reminder->title}",
            'cancelled' => "Cancelled - {$reminder->title}",
            'snoozed' => 'Snoozed until '.$reminder->next_reminder_at?->setTimezone($reminder->timezone ?: self::DEFAULT_TIMEZONE)->format('g:i A')." - {$reminder->title}",
            'exhausted' => "Reminder exhausted - {$reminder->title}",
            default => "Reminder: {$reminder->title}",
        };
    }

    private function updateSlackActionMessage(array $payload, string $message): void
    {
        $blocks = [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $message,
                ],
            ],
        ];

        $responseUrl = (string) ($payload['response_url'] ?? '');

        if (filled($responseUrl)) {
            try {
                Http::asJson()->timeout(5)->post($responseUrl, [
                    'replace_original' => true,
                    'response_type' => 'in_channel',
                    'text' => $message,
                    'blocks' => $blocks,
                ]);
            } catch (\Throwable) {
                //
            }

            return;
        }

        $token = config('services.slack.bot_token');
        $channel = (string) data_get($payload, 'channel.id', '');
        $ts = (string) data_get($payload, 'message.ts', '');

        if (! filled($token) || ! filled($channel) || ! filled($ts)) {
            return;
        }

        try {
            Http::withToken($token)
                ->acceptJson()
                ->timeout(5)
                ->post('https://slack.com/api/chat.update', [
                    'channel' => $channel,
                    'ts' => $ts,
                    'text' => $message,
                    'blocks' => $blocks,
                ]);
        } catch (\Throwable) {
            //
        }
    }

    private function parseTimeOnDate(string $time, CarbonImmutable $date): CarbonImmutable
    {
        $time = trim($time);
        $parsed = CarbonImmutable::parse($date->toDateString().' '.$time, self::DEFAULT_TIMEZONE);

        return $date->setTime((int) $parsed->format('H'), (int) $parsed->format('i'));
    }

    private function normalizeReminderText(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::of($text)
            ->lower()
            ->replaceMatches('/<@[a-z0-9]+>/i', '')
            ->replaceMatches('/^@miriam[:,]?\s*/i', '')
            ->replaceMatches('/^miriam[:,]?\s*/i', '')
            ->toString()));
    }

    private function cleanTitle(string $title): string
    {
        return trim(Str::of($title)->replaceMatches('/\s+/', ' ')->toString(), " \t\n\r\0\x0B.");
    }

    private function categoryFor(string $title): string
    {
        $value = Str::lower($title);

        return match (true) {
            Str::contains($value, ['medicine', 'medication', 'dose', 'tablet', 'injection']) => 'medication',
            Str::contains($value, ['codex', 'deploy', 'release', 'development', 'churchforce', 'catererhq']) => 'development',
            Str::contains($value, ['sunny', 'family', 'mom', 'dad', 'wife', 'son', 'daughter']) => 'family',
            Str::contains($value, ['client', 'invoice', 'meeting', 'work']) => 'work',
            default => 'personal',
        };
    }

    private function dueReminderBlocks(MiriamReminder $reminder, int $attempt = 1): array
    {
        $reminder->loadMissing('task.project');
        $project = $reminder->task?->project?->name ?: ($reminder->metadata['project_name'] ?? null);
        $due = $reminder->due_at
            ? $reminder->due_at->setTimezone($reminder->timezone ?: self::DEFAULT_TIMEZONE)->format('M j, g:i A')
            : 'No due time';
        $maxPokes = $this->maxPokes();
        $taskUrl = $reminder->task_id ? route('tasks.show', $reminder->task_id, true) : null;

        $blocks = [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => implode("\n", array_filter([
                        "*Reminder: {$reminder->title}*",
                        $project ? "Project: {$project}" : null,
                        "Due: {$due}",
                        'Status: Open',
                        "Poke {$attempt} of {$maxPokes}",
                    ])),
                ],
            ],
            [
                'type' => 'actions',
                'elements' => [
                    [
                        'type' => 'button',
                        'style' => 'primary',
                        'text' => ['type' => 'plain_text', 'text' => 'Done'],
                        'action_id' => 'miriam_reminder_done',
                        'value' => (string) $reminder->id,
                    ],
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Snooze 15 min'],
                        'action_id' => 'miriam_reminder_snooze_15',
                        'value' => (string) $reminder->id,
                    ],
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Snooze 1 hour'],
                        'action_id' => 'miriam_reminder_snooze_60',
                        'value' => (string) $reminder->id,
                    ],
                    // Cancel disappeared from this card during the escalation
                    // refactor even though the handler stayed. Without it there
                    // was no way to stop a reminder you no longer needed
                    // without marking work you had not done as done.
                    [
                        'type' => 'button',
                        'style' => 'danger',
                        'text' => ['type' => 'plain_text', 'text' => 'Cancel'],
                        'action_id' => 'miriam_reminder_cancel',
                        'value' => (string) $reminder->id,
                    ],
                ],
            ],
            [
                'type' => 'actions',
                'elements' => array_values(array_filter([
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Tonight'],
                        'action_id' => 'miriam_reminder_tonight',
                        'value' => (string) $reminder->id,
                    ],
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Tomorrow'],
                        'action_id' => 'miriam_reminder_tomorrow',
                        'value' => (string) $reminder->id,
                    ],
                    [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Move to Today'],
                        'action_id' => 'miriam_reminder_move_today',
                        'value' => (string) $reminder->id,
                    ],
                    $taskUrl ? [
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'Open task'],
                        'url' => $taskUrl,
                        'value' => (string) $reminder->id,
                    ] : null,
                ])),
            ],
        ];

        return $blocks;
    }

    private function sendSlack(?string $channel, string $text, array $blocks = []): array
    {
        $token = config('services.slack.bot_token');
        $targetChannel = $this->miriamChannel($channel);

        if (! filled($token) || ! filled($targetChannel)) {
            return ['ok' => false, 'error' => 'missing_slack_configuration'];
        }

        $payload = [
            'channel' => $targetChannel,
            'text' => $text,
        ];

        if ($blocks !== []) {
            $payload['blocks'] = $blocks;
        }

        try {
            return Http::withToken($token)
                ->acceptJson()
                ->post('https://slack.com/api/chat.postMessage', $payload)
                ->json() ?? ['ok' => false, 'error' => 'empty_slack_response'];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'error' => class_basename($exception)];
        }
    }

    private function miriamChannel(?string $fallback = null): ?string
    {
        // config() only — env() at runtime returns null under config:cache,
        // which silently redirected reminders to the fallback channel.
        return config('services.slack.miriam_channel_id')
            ?: $fallback
            ?: config('services.slack.default_channel');
    }
}
