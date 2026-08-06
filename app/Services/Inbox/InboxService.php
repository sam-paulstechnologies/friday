<?php

namespace App\Services\Inbox;

use App\Models\MiriamReminder;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Miriam\MiriamSlackThoughtCaptureService;
use App\Services\Tasks\InvalidTaskTransitionException;
use App\Services\Tasks\TaskTransitionService;
use App\Support\OperationalClock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The Inbox reads the capture domain that already exists; it does not invent
 * a second one.
 *
 * A capture arrives as one of two records:
 *
 *  - SOURCE_CAPTURE — a MiriamReminder still awaiting confirmation. The thought
 *    was understood well enough to propose a time, but no task exists yet.
 *  - SOURCE_TASK — a Task in the `inbox` workflow state. Either a web Quick
 *    Capture, or a Slack capture with no time to confirm. It was written
 *    straight down and still needs triage.
 *
 * Both are normalised into one item shape so the page, the tests and the
 * transitions do not care which one they are looking at.
 */
class InboxService
{
    public const SOURCE_CAPTURE = 'capture';

    public const SOURCE_TASK = 'task';

    public const SOURCES = [self::SOURCE_CAPTURE, self::SOURCE_TASK];

    public const STATE_UNPROCESSED = 'unprocessed';

    public const STATE_CLARIFICATION_NEEDED = 'clarification_needed';

    public const STATE_CONVERTED = 'converted';

    public const STATE_DISMISSED = 'dismissed';

    public const STATE_DUPLICATE = 'duplicate';

    /** Captures the operator still has to deal with. */
    public const OPEN_STATES = [self::STATE_UNPROCESSED, self::STATE_CLARIFICATION_NEEDED];

    public function __construct(
        private readonly OperationalClock $clock,
        private readonly MiriamSlackThoughtCaptureService $captureService,
        private readonly TaskTransitionService $transitions,
    ) {}

    /**
     * Everything in the Inbox for this operator, unresolved first.
     *
     * Resolved captures are still returned (grouped separately by the page) so
     * nothing that was captured silently disappears.
     */
    public function items(User $user): array
    {
        $items = collect()
            ->merge($this->captureItems($user))
            ->merge($this->taskItems($user))
            ->sortByDesc(fn (array $item): string => (string) $item['captured_at'])
            ->values();

        [$open, $resolved] = $items->partition(
            fn (array $item): bool => in_array($item['state'], self::OPEN_STATES, true)
        );

        return [
            'open' => $open->values()->all(),
            'resolved' => $resolved->take(30)->values()->all(),
            'counts' => [
                'open' => $open->count(),
                'unprocessed' => $open->where('state', self::STATE_UNPROCESSED)->count(),
                'clarification_needed' => $open->where('state', self::STATE_CLARIFICATION_NEEDED)->count(),
                'converted' => $items->where('state', self::STATE_CONVERTED)->count(),
                'dismissed' => $items->where('state', self::STATE_DISMISSED)->count(),
                'duplicate' => $items->where('state', self::STATE_DUPLICATE)->count(),
            ],
        ];
    }

    /**
     * One Inbox item, with the options the operator can act on.
     *
     * @throws AuthorizationException
     */
    public function show(User $user, string $source, int $id): array
    {
        $item = $source === self::SOURCE_CAPTURE
            ? $this->captureItem($this->reminderFor($user, $id))
            : $this->taskItem($this->taskFor($user, $id));

        return $item + [
            'projects' => $this->projectOptions($user),
            'priorities' => Task::PRIORITIES,
            'task_types' => Task::TYPES,
        ];
    }

    /**
     * Convert a capture into exactly one task.
     *
     * Repeating this — a double click, a Slack retry, a refreshed form — always
     * resolves to the same task.
     *
     * @return array{task: Task, created: bool}
     *
     * @throws AuthorizationException|InboxConversionException
     */
    public function convert(User $user, string $source, int $id, array $attributes = []): array
    {
        $overrides = $this->sanitiseOverrides($user, $attributes);

        if ($source === self::SOURCE_CAPTURE) {
            $reminder = $this->reminderFor($user, $id);
            $result = $this->captureService->convertReminderToTask(
                $reminder,
                $user,
                $overrides,
                moveToToday: ($attributes['destination'] ?? null) === TaskTransitionService::MOVE_TODAY,
            );

            if (! ($result['ok'] ?? false) || ! $result['task']) {
                throw InboxConversionException::for((string) ($result['reason'] ?? 'unknown'));
            }

            $task = $result['task'];
            $created = (bool) $result['created'];
        } else {
            $task = $this->taskFor($user, $id);
            $created = false;
            $this->applyTaskOverrides($task, $overrides, $user);
        }

        $destination = $this->destination($attributes);
        $task = $this->transitions->apply($task, $destination, $user, [
            'source' => 'inbox',
            'reason' => 'Converted from Inbox.',
        ]);

        return ['task' => $task, 'created' => $created];
    }

    /**
     * Move an Inbox item straight to a daily bucket without opening it.
     *
     * @throws AuthorizationException|InboxConversionException|InvalidTaskTransitionException
     */
    public function move(User $user, string $source, int $id, string $transition): array
    {
        return $this->convert($user, $source, $id, ['destination' => $transition]);
    }

    /**
     * Dismiss a capture. Nothing is deleted: the wording and the trail stay.
     *
     * @throws AuthorizationException
     */
    public function dismiss(User $user, string $source, int $id): array
    {
        if ($source === self::SOURCE_CAPTURE) {
            $reminder = $this->reminderFor($user, $id);

            if ($reminder->task_id) {
                // The capture already became a task; dismiss that instead so
                // the two records cannot disagree.
                $task = Task::query()->find($reminder->task_id);

                if ($task) {
                    return ['task' => $this->transitions->apply($task, TaskTransitionService::DISMISS, $user, [
                        'source' => 'inbox',
                        'reason' => 'Dismissed from Inbox.',
                    ])];
                }
            }

            if (! in_array($reminder->status, ['cancelled', 'done'], true)) {
                $reminder->forceFill([
                    'status' => 'cancelled',
                    'cancelled_at' => CarbonImmutable::now('UTC'),
                    'next_reminder_at' => null,
                    'metadata' => array_merge($reminder->metadata ?? [], [
                        'capture_status' => 'dismissed',
                        'dismissed_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                        'dismissed_by_user_id' => $user->id,
                    ]),
                ])->save();

                $reminder->events()->create([
                    'event_type' => 'capture_dismissed',
                    'channel' => 'inbox',
                    'occurred_at' => CarbonImmutable::now('UTC'),
                    'metadata' => ['dismissed_by_user_id' => $user->id],
                ]);
            }

            return ['reminder' => $reminder->refresh()];
        }

        $task = $this->taskFor($user, $id);

        return ['task' => $this->transitions->apply($task, TaskTransitionService::DISMISS, $user, [
            'source' => 'inbox',
            'reason' => 'Dismissed from Inbox.',
        ])];
    }

    /** Number of unresolved captures, for the navigation badge. */
    public function openCount(User $user): int
    {
        return MiriamReminder::query()
            ->where('user_id', $user->id)
            ->where('status', 'awaiting_confirmation')
            ->whereNull('task_id')
            ->count()
            + Task::query()->inInbox()->where($this->taskOwnership($user))->count();
    }

    // ---------------------------------------------------------------- reads

    private function captureItems(User $user): Collection
    {
        return $this->pendingReminderQuery($user)
            ->with('task:id,title')
            ->latest('id')
            ->limit(200)
            ->get()
            ->map(fn (MiriamReminder $reminder): array => $this->captureItem($reminder));
    }

    private function taskItems(User $user): Collection
    {
        return Task::query()
            ->with(['project:id,name'])
            ->where($this->taskOwnership($user))
            ->where(function ($query): void {
                $query->where('workflow_state', Task::WORKFLOW_INBOX)
                    ->orWhere('workflow_state', Task::WORKFLOW_DISMISSED);
            })
            ->latest('id')
            ->limit(200)
            ->get()
            // A converted capture is represented by its reminder row, so the
            // same thought never appears twice in the list.
            ->reject(fn (Task $task): bool => (bool) ($task->source_metadata['capture_reminder_id'] ?? null))
            ->map(fn (Task $task): array => $this->taskItem($task));
    }

    private function captureItem(MiriamReminder $reminder): array
    {
        $metadata = $reminder->metadata ?? [];
        $state = $this->captureState($reminder);

        return [
            'key' => self::SOURCE_CAPTURE.'-'.$reminder->id,
            'source' => self::SOURCE_CAPTURE,
            'id' => $reminder->id,
            'state' => $state,
            'state_label' => $this->stateLabel($state),
            'title' => (string) $reminder->title,
            'details' => (string) ($metadata['description'] ?? ''),
            'original_text' => (string) ($metadata['original_text'] ?? $reminder->title),
            'capture_source' => $this->captureSource($metadata, $reminder->slack_channel_id),
            'captured_at' => $reminder->created_at?->toIso8601String(),
            'captured_at_local' => $this->clock->toLocal($reminder->created_at)?->format('M j, Y g:i A'),
            'proposed' => [
                'due_date' => $metadata['due_date'] ?? $this->clock->localDateString($reminder->due_at),
                'due_label' => $metadata['due_label'] ?? null,
                'due_time' => $metadata['due_time'] ?? null,
                'priority' => $metadata['priority'] ?? 'medium',
                'task_type' => $metadata['task_type'] ?? 'task',
                'display_type' => $metadata['display_type'] ?? Str::headline((string) ($reminder->item_type ?: 'Reminder')),
                'project_id' => $metadata['project_id'] ?? null,
                'project_name' => $metadata['project_name'] ?? null,
                'confidence' => $reminder->confidence !== null ? (float) $reminder->confidence : null,
            ],
            'task' => $reminder->task ? [
                'id' => $reminder->task->id,
                'title' => $reminder->task->title,
                'url' => route('tasks.show', $reminder->task->id),
            ] : null,
            'can_convert' => $state === self::STATE_UNPROCESSED || $state === self::STATE_CLARIFICATION_NEEDED,
            'can_dismiss' => $state !== self::STATE_DISMISSED,
        ];
    }

    private function taskItem(Task $task): array
    {
        $metadata = $task->source_metadata ?? [];
        $needsReview = (bool) ($metadata['needs_review'] ?? false);

        $state = match (true) {
            $task->workflow_state === Task::WORKFLOW_DISMISSED => self::STATE_DISMISSED,
            $task->workflow_state !== Task::WORKFLOW_INBOX => self::STATE_CONVERTED,
            // Miriam kept the words but could not interpret them.
            $needsReview => self::STATE_CLARIFICATION_NEEDED,
            default => self::STATE_UNPROCESSED,
        };

        return [
            'key' => self::SOURCE_TASK.'-'.$task->id,
            'source' => self::SOURCE_TASK,
            'id' => $task->id,
            'state' => $state,
            'state_label' => $this->stateLabel($state),
            'title' => (string) $task->title,
            'details' => (string) ($task->description ?? ''),
            'original_text' => (string) ($metadata['original_text'] ?? $task->description ?? $task->title),
            'capture_source' => $this->captureSource($metadata, $metadata['slack_channel_id'] ?? null, $task->source),
            'captured_at' => $task->created_at?->toIso8601String(),
            'captured_at_local' => $this->clock->toLocal($task->created_at)?->format('M j, Y g:i A'),
            'proposed' => [
                'due_date' => $task->due_date?->toDateString(),
                'due_label' => $metadata['due_label'] ?? null,
                'due_time' => $metadata['due_time'] ?? null,
                'priority' => $task->priority,
                'task_type' => $task->task_type ?? 'task',
                'display_type' => $metadata['display_type'] ?? 'Task',
                'project_id' => $task->project_id,
                'project_name' => $task->project?->name ?? ($metadata['project_name'] ?? null),
                'confidence' => isset($metadata['confidence']) ? (float) $metadata['confidence'] : null,
            ],
            // A capture that is still untriaged IS the task record, so
            // advertising a "resulting task" before conversion would be a lie.
            'task' => $state === self::STATE_CONVERTED ? [
                'id' => $task->id,
                'title' => $task->title,
                'url' => route('tasks.show', $task->id),
            ] : null,
            'can_convert' => in_array($state, self::OPEN_STATES, true),
            'can_dismiss' => $state !== self::STATE_DISMISSED,
        ];
    }

    private function captureState(MiriamReminder $reminder): string
    {
        if ($reminder->task_id) {
            return self::STATE_CONVERTED;
        }

        return match ($reminder->status) {
            'awaiting_confirmation' => ($reminder->metadata['capture_status'] ?? null) === 'clarification_needed'
                ? self::STATE_CLARIFICATION_NEEDED
                : self::STATE_UNPROCESSED,
            'cancelled', 'expired' => self::STATE_DISMISSED,
            default => self::STATE_CONVERTED,
        };
    }

    private function stateLabel(string $state): string
    {
        return match ($state) {
            self::STATE_UNPROCESSED => 'Unprocessed',
            self::STATE_CLARIFICATION_NEEDED => 'Needs clarification',
            self::STATE_CONVERTED => 'Converted',
            self::STATE_DISMISSED => 'Dismissed',
            self::STATE_DUPLICATE => 'Duplicate',
            default => Str::headline($state),
        };
    }

    private function captureSource(array $metadata, ?string $channel = null, ?string $fallback = null): string
    {
        $source = $metadata['source'] ?? $fallback;

        return match (true) {
            $source === 'slack' || $source === 'slack_thought_capture' || filled($channel) => 'Slack',
            $source === 'web_quick_capture' => 'Quick Capture',
            $source === 'web' || $source === null => 'Web',
            default => Str::headline((string) $source),
        };
    }

    // ------------------------------------------------------- authorization

    /**
     * Captures belong to a person. An id from another operator resolves to
     * nothing rather than to somebody else's thought.
     */
    private function pendingReminderQuery(User $user)
    {
        return MiriamReminder::query()
            ->where('user_id', $user->id)
            ->where(function ($query): void {
                // Anything that arrived as a capture, at any point in its
                // lifecycle — awaiting confirmation, converted, or dismissed.
                $query->where('status', 'awaiting_confirmation')
                    ->orWhereNotNull('source_dedupe_key');
            });
    }

    /** @throws AuthorizationException */
    private function reminderFor(User $user, int $id): MiriamReminder
    {
        $reminder = MiriamReminder::query()->with('task:id,title')->find($id);

        if (! $reminder || $reminder->user_id !== $user->id) {
            throw new AuthorizationException('That capture does not belong to you.');
        }

        return $reminder;
    }

    /** @throws AuthorizationException */
    private function taskFor(User $user, int $id): Task
    {
        $task = Task::query()->with('project:id,name')->find($id);

        if (! $task) {
            throw new AuthorizationException('That capture does not belong to you.');
        }

        Gate::forUser($user)->authorize('update', $task);

        return $task;
    }

    private function taskOwnership(User $user): callable
    {
        return function ($query) use ($user): void {
            $query->where('assignee_id', $user->id)
                ->orWhere('reporter_id', $user->id);
        };
    }

    // ------------------------------------------------------------- writing

    private function destination(array $attributes): string
    {
        $destination = (string) ($attributes['destination'] ?? TaskTransitionService::MOVE_TASKS);

        return array_key_exists($destination, TaskTransitionService::MOVES)
            ? $destination
            : TaskTransitionService::MOVE_TASKS;
    }

    /**
     * Only values the operator actually supplied are honoured, and a project
     * is only accepted when it is a real record they can reach.
     */
    private function sanitiseOverrides(User $user, array $attributes): array
    {
        $overrides = [];

        foreach (['title', 'description', 'due_date', 'priority', 'task_type'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $overrides[$field] = $attributes[$field];
            }
        }

        if (array_key_exists('project_id', $attributes)) {
            $projectId = $attributes['project_id'] !== null && $attributes['project_id'] !== ''
                ? (int) $attributes['project_id']
                : null;

            $overrides['project_id'] = $projectId !== null && $this->projectIsReachable($user, $projectId)
                ? $projectId
                : null;
        }

        return $overrides;
    }

    private function applyTaskOverrides(Task $task, array $overrides, User $user): void
    {
        $changes = [];

        if (filled($overrides['title'] ?? null)) {
            $changes['title'] = (string) $overrides['title'];
        }

        if (filled($overrides['description'] ?? null)) {
            $changes['description'] = (string) $overrides['description'];
        }

        if (array_key_exists('due_date', $overrides)) {
            $changes['due_date'] = filled($overrides['due_date']) ? (string) $overrides['due_date'] : null;
        }

        if (in_array($overrides['priority'] ?? null, Task::PRIORITIES, true)) {
            $changes['priority'] = (string) $overrides['priority'];
        }

        if (in_array($overrides['task_type'] ?? null, Task::TYPES, true)) {
            $changes['task_type'] = (string) $overrides['task_type'];
        }

        if (array_key_exists('project_id', $overrides)) {
            $changes['project_id'] = $overrides['project_id'];
        }

        if ($changes === []) {
            return;
        }

        // forceFill leaves source/original wording untouched.
        $task->forceFill($changes)->save();

        $task->activities()->create([
            'user_id' => $user->id,
            'action' => 'capture_details_edited',
            'description' => 'Interpreted details corrected in the Inbox.',
        ]);
    }

    private function projectIsReachable(User $user, int $projectId): bool
    {
        return Project::query()
            ->whereKey($projectId)
            ->whereIn('workspace_id', $user->accessibleWorkspaceIds())
            ->exists();
    }

    private function projectOptions(User $user): array
    {
        $workspaceIds = $user->accessibleWorkspaceIds();

        if ($workspaceIds === []) {
            return [];
        }

        return Project::query()
            ->select(['id', 'name'])
            ->whereIn('workspace_id', $workspaceIds)
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project): array => ['id' => $project->id, 'name' => $project->name])
            ->all();
    }
}
