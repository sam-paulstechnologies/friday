<?php

namespace App\Services\Tasks;

use App\Models\AuditLog;
use App\Models\Task;
use App\Models\User;
use App\Services\MiriamReminderService;
use App\Support\OperationalClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The one place a task changes daily-loop state.
 *
 * Inbox, Today, the task list, Slack and reminders all call this instead of
 * writing `status`/`section` themselves, so authorization, validation, audit
 * history and reminder synchronisation cannot drift between surfaces.
 */
class TaskTransitionService
{
    public const MOVE_TODAY = 'today';

    public const MOVE_THIS_WEEK = 'this_week';

    public const MOVE_THIS_MONTH = 'this_month';

    public const MOVE_LATER = 'later';

    public const MOVE_WAITING = 'waiting';

    public const MOVE_DELEGATED = 'delegated';

    public const MOVE_TASKS = 'tasks';

    public const COMPLETE = 'complete';

    public const REOPEN = 'reopen';

    public const DISMISS = 'dismiss';

    /**
     * Transitions that only re-bucket an open task.
     *
     * The value is written to `workflow_state`. It never touches `section`,
     * which belongs to the operator's own project grouping labels.
     */
    public const MOVES = [
        self::MOVE_TODAY => Task::WORKFLOW_TODAY,
        self::MOVE_THIS_WEEK => Task::WORKFLOW_THIS_WEEK,
        self::MOVE_THIS_MONTH => Task::WORKFLOW_THIS_MONTH,
        self::MOVE_LATER => Task::WORKFLOW_LATER,
        self::MOVE_WAITING => Task::WORKFLOW_WAITING,
        self::MOVE_DELEGATED => Task::WORKFLOW_DELEGATED,
        self::MOVE_TASKS => Task::WORKFLOW_TASKS,
    ];

    public const TRANSITIONS = [
        self::MOVE_TODAY,
        self::MOVE_THIS_WEEK,
        self::MOVE_THIS_MONTH,
        self::MOVE_LATER,
        self::MOVE_WAITING,
        self::MOVE_DELEGATED,
        self::MOVE_TASKS,
        self::COMPLETE,
        self::REOPEN,
        self::DISMISS,
    ];

    public function __construct(
        private readonly OperationalClock $clock,
        private readonly MiriamReminderService $reminders,
    ) {}

    public function isKnown(string $transition): bool
    {
        return in_array($transition, self::TRANSITIONS, true);
    }

    /**
     * Apply a transition. Authorization is enforced here, not by the caller.
     *
     * @param  array{reason?: string|null, source?: string|null}  $options
     *
     * @throws InvalidTaskTransitionException
     */
    public function apply(Task $task, string $transition, ?User $actor = null, array $options = []): Task
    {
        if (! $this->isKnown($transition)) {
            throw InvalidTaskTransitionException::unknown($transition);
        }

        if ($actor) {
            Gate::forUser($actor)->authorize('update', $task);
        }

        $this->guard($task, $transition);

        $source = (string) ($options['source'] ?? 'app');
        $reason = $options['reason'] ?? null;

        $updated = DB::transaction(function () use ($task, $transition, $actor, $source, $reason): Task {
            $before = [
                'status' => $task->status,
                'workflow_state' => $task->workflow_state,
            ];

            $changes = match (true) {
                $transition === self::COMPLETE => $this->completeChanges($task),
                $transition === self::REOPEN => $this->reopenChanges($task),
                $transition === self::DISMISS => $this->dismissChanges(),
                default => $this->moveChanges($task, $transition),
            };

            if ($changes !== []) {
                // forceFill keeps source_dedupe_key / source_metadata untouched,
                // so capture provenance survives every transition.
                $task->forceFill($changes)->save();
            }

            $this->recordHistory($task, $transition, $before, $actor, $source, $reason);

            return $task->refresh();
        });

        $this->reminders->syncAfterTaskSaved($updated, $actor, $this->reschedulesReminders($transition));

        return $updated;
    }

    /** Transitions that are legal for the task's current state. */
    public function availableFor(Task $task): array
    {
        return array_values(array_filter(
            self::TRANSITIONS,
            function (string $transition) use ($task): bool {
                try {
                    $this->guard($task, $transition);
                } catch (InvalidTaskTransitionException) {
                    return false;
                }

                return true;
            }
        ));
    }

    /** @throws InvalidTaskTransitionException */
    private function guard(Task $task, string $transition): void
    {
        $status = (string) $task->status;
        $isClosed = in_array($status, Task::CLOSED_STATUSES, true);

        if ($transition === self::REOPEN && ! $isClosed) {
            throw InvalidTaskTransitionException::notAllowed($transition, $status);
        }

        if (array_key_exists($transition, self::MOVES) && $isClosed) {
            // Re-bucketing finished work would put it back into active lists
            // without an explicit reopen, which is exactly the drift this
            // service exists to prevent.
            throw InvalidTaskTransitionException::notAllowed($transition, $status);
        }

        if ($transition === self::COMPLETE && $status === 'archived') {
            throw InvalidTaskTransitionException::notAllowed($transition, $status);
        }
    }

    private function completeChanges(Task $task): array
    {
        if ($task->status === 'completed') {
            return []; // already done — idempotent, no second audit entry needed
        }

        return [
            'status' => 'completed',
            'completed_at' => $task->completed_at ?: CarbonImmutable::now('UTC'),
        ];
    }

    private function reopenChanges(Task $task): array
    {
        $state = $task->workflow_state;

        return [
            'status' => 'todo',
            'completed_at' => null,
            // A reopened task must land somewhere active; it must not fall
            // back into the Inbox or stay dismissed.
            'workflow_state' => in_array($state, Task::INACTIVE_WORKFLOW_STATES, true) || $state === null
                ? Task::WORKFLOW_TASKS
                : $state,
        ];
    }

    private function dismissChanges(): array
    {
        return [
            'status' => 'archived',
            'workflow_state' => Task::WORKFLOW_DISMISSED,
            'completed_at' => null,
        ];
    }

    private function moveChanges(Task $task, string $transition): array
    {
        $changes = ['workflow_state' => self::MOVES[$transition]];

        if ($transition === self::MOVE_TODAY) {
            // Choosing "Today" is an explicit reschedule onto the operator's
            // current calendar day, so the due date follows it.
            $changes['due_date'] = $this->clock->todayString();
        }

        if ($transition === self::MOVE_WAITING && $task->task_type !== 'waiting_for') {
            $changes['task_type'] = 'waiting_for';
        }

        return $changes;
    }

    private function reschedulesReminders(string $transition): bool
    {
        return $transition === self::MOVE_TODAY;
    }

    private function recordHistory(Task $task, string $transition, array $before, ?User $actor, string $source, ?string $reason): void
    {
        $after = [
            'status' => $task->status,
            'workflow_state' => $task->workflow_state,
        ];

        if ($before === $after) {
            return;
        }

        $action = $this->activityAction($transition);

        $task->activities()->create([
            'user_id' => $actor?->id,
            'action' => $action,
            'description' => $reason
                ?: sprintf('Moved to %s from %s.', $this->label($transition), $source),
            'old_value' => $this->stateLabel($before),
            'new_value' => $this->stateLabel($after),
        ]);

        if ($task->workspace_id !== null) {
            AuditLog::record($task->workspace_id, $actor?->id, $action, $task, [
                'task_title' => $task->title,
                'from' => $before,
                'to' => $after,
                'source' => $source,
            ]);
        }
    }

    /**
     * Keep the vocabulary the rest of the application already records against,
     * so existing activity feeds and audit queries keep working.
     */
    private function activityAction(string $transition): string
    {
        return match ($transition) {
            self::COMPLETE => 'task_completed',
            self::REOPEN => 'task_reopened',
            self::DISMISS => 'task_dismissed',
            default => "task_moved_{$transition}",
        };
    }

    private function stateLabel(array $state): string
    {
        return trim(($state['status'] ?? '').'/'.($state['workflow_state'] ?? 'none'));
    }

    private function label(string $transition): string
    {
        return match ($transition) {
            self::COMPLETE => 'Completed',
            self::REOPEN => 'Reopened',
            self::DISMISS => 'Dismissed',
            self::MOVE_TODAY => 'Today',
            self::MOVE_THIS_WEEK => 'This week',
            self::MOVE_THIS_MONTH => 'This month',
            self::MOVE_LATER => 'Later',
            self::MOVE_WAITING => 'Waiting',
            self::MOVE_DELEGATED => 'Delegated',
            self::MOVE_TASKS => 'Tasks',
            default => $transition,
        };
    }
}
