<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\AgentOutput;
use App\Models\Blocker;
use App\Models\MiriamDevelopmentFailure;
use App\Models\MiriamDevelopmentJob;
use App\Models\Task;
use App\Models\User;
use App\Models\WaitingItem;
use App\Models\MiriamReminder;
use App\Services\DailyReview\DailyReviewService;
use App\Services\Health\MedicationReminderService;
use App\Support\OperationalClock;
use Illuminate\Support\Collection;

class TodayCommandCenterService
{
    /**
     * Services the Codex / development pipeline needs in order to do anything.
     * They are absent from this build, so the panel reports itself unavailable
     * rather than implying work can be started from Today.
     */
    private const DEVELOPMENT_MODULE_CLASSES = [
        'App\Services\MiriamRunnerMonitoringService',
        'App\Services\MiriamPromptQueueService',
        'App\Services\MiriamAppRegistryService',
    ];

    private const ACTIVE_DEVELOPMENT_STATUSES = [
        'queued',
        'waiting_for_runner',
        'preparing',
        'running',
        'waiting_for_approval',
        'waiting_for_manual_fix',
        'paused',
        'blocked',
        'failed',
    ];

    private const WAITING_DEVELOPMENT_STATUSES = [
        'waiting_for_approval',
        'waiting_for_manual_fix',
        'paused',
    ];

    private const BLOCKED_DEVELOPMENT_STATUSES = [
        'blocked',
        'failed',
        'waiting_for_manual_fix',
    ];

    private const PRODUCT_BUCKETS = [
        'sayaraforce' => [
            'label' => 'SayaraForce',
            'needles' => ['sayara', 'sayaraforce'],
        ],
        'churchforce' => [
            'label' => 'ChurchForce',
            'needles' => ['churchforce', 'church force'],
        ],
        'cffa_academy' => [
            'label' => 'CFFA Academy',
            'needles' => ['cffa', 'academy'],
        ],
        'miriam_friday' => [
            'label' => 'Miriam/Friday',
            'needles' => ['miriam', 'friday'],
        ],
        'work_survival' => [
            'label' => 'Work survival',
            'needles' => ['work survival', 'career', 'publicis', 'digitas', 'stellantis', 'client'],
        ],
        'personal_health' => [
            'label' => 'Personal/Health',
            'needles' => ['health', 'medicine', 'medication', 'personal', 'gym', 'sleep', 'wellness'],
        ],
        'finance_life_admin' => [
            'label' => 'Finance/Life admin',
            'needles' => ['finance', 'money', 'bill', 'bank', 'admin', 'life admin'],
        ],
    ];

    public function __construct(
        private readonly DailyReviewService $dailyReviewService,
        private readonly MedicationReminderService $medicationReminderService,
        private readonly OperationalClock $clock,
    ) {}

    public function forUser(User $user): array
    {
        $groups = $this->dailyReviewService->collectTodayTasks($user);
        $medication = $this->medicationReminderService->statusForUser($user);
        $jobs = $this->developmentJobs();
        $failures = $this->developmentFailures();
        $waitingItems = $this->waitingItems($user);
        $blockedItems = $this->blockedItems($user, $groups, $failures);
        $doThisNow = $this->doThisNow($groups, $medication, $waitingItems);

        return [
            'metrics' => $this->metrics($groups, $medication, $jobs, $failures, $waitingItems, $blockedItems),
            'hero_status' => $this->heroStatus($medication, $jobs),
            'do_this_now' => $doThisNow,
            'waiting_on_me' => $waitingItems,
            'codex_workstream' => $this->codexWorkstream($jobs),
            'products' => $this->products($user),
            'overdue_blocked' => $blockedItems,
            'later_backlog' => $this->laterBacklog($groups),
            'reminders' => $this->reminders($user),
            'empty_state' => $this->emptyState($doThisNow, $medication, $jobs),
        ];
    }

    /**
     * Reminders that are due now or were recently missed.
     *
     * Today and Slack read the same `next_reminder_at` field, so both agree on
     * whether a reminder is due.
     */
    private function reminders(User $user): array
    {
        $now = $this->clock->now();

        return MiriamReminder::query()
            ->with('task:id,title')
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending', 'snoozed', 'exhausted'])
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $now->addHours(12)->utc())
            ->orderBy('due_at')
            ->limit(10)
            ->get()
            ->map(function (MiriamReminder $reminder) use ($now): array {
                $dueLocal = $this->clock->toLocal($reminder->due_at);
                $isDue = $reminder->due_at !== null && $reminder->due_at->lessThanOrEqualTo($now->utc());

                return [
                    'id' => $reminder->id,
                    'title' => $reminder->title,
                    'status' => $reminder->status,
                    'state' => $reminder->status === 'exhausted'
                        ? 'missed'
                        : ($isDue ? 'due' : 'scheduled'),
                    'due_at_local' => $dueLocal?->format('M j, g:i A'),
                    'attempts' => (int) $reminder->reminder_attempts,
                    'delivery_failed' => ($reminder->metadata['reminder_status'] ?? null) === 'exhausted'
                        || ($reminder->status === 'exhausted'),
                    'task' => $reminder->task ? [
                        'id' => $reminder->task->id,
                        'title' => $reminder->task->title,
                    ] : null,
                    'href' => $reminder->task_id
                        ? route('tasks.show', $reminder->task_id, false)
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    private function metrics(Collection $groups, array $medication, Collection $jobs, Collection $failures, array $waitingItems, array $blockedItems): array
    {
        $overdue = $groups->get('overdue', collect())->count() + (int) ($medication['overdue_count'] ?? 0);
        $waiting = count($waitingItems);
        $blocked = count($blockedItems) + $failures->where('status', 'open')->count();

        return [
            'critical' => $overdue + $waiting + $blocked + $jobs->whereIn('status', ['failed', 'blocked', 'waiting_for_manual_fix'])->count(),
            'overdue' => $overdue,
            'due_today' => $groups->get('due_today', collect())->count() + $groups->get('scheduled_today', collect())->count(),
            'waiting_for_me' => $waiting,
            'codex_running' => $jobs->whereIn('status', ['queued', 'waiting_for_runner', 'preparing', 'running'])->count(),
            'blocked' => $blocked,
            'health_medicine' => $medication['status_label'] ?? 'Medication unknown',
        ];
    }

    private function heroStatus(array $medication, Collection $jobs): array
    {
        $running = $jobs->whereIn('status', ['queued', 'waiting_for_runner', 'preparing', 'running'])->count();
        $blocked = $jobs->whereIn('status', self::BLOCKED_DEVELOPMENT_STATUSES)->count();

        return [
            'health' => [
                'label' => $medication['status_label'] ?? 'Medication unknown',
                'tone' => (int) ($medication['overdue_count'] ?? 0) > 0 ? 'critical' : ((int) ($medication['pending_count'] ?? 0) > 0 ? 'waiting' : 'stable'),
            ],
            'codex' => [
                'label' => $blocked > 0 ? 'Codex blocked' : ($running > 0 ? 'Codex running' : 'Codex idle'),
                'tone' => $blocked > 0 ? 'critical' : ($running > 0 ? 'running' : 'stable'),
            ],
        ];
    }

    private function doThisNow(Collection $groups, array $medication, array $waitingItems): array
    {
        $items = collect();

        $this->activeMedicationItems($medication)
            ->sortByDesc(fn (array $item) => $item['critical_overdue'] ? 2 : ($item['overdue'] ? 1 : 0))
            ->each(function (array $item) use ($items): void {
                $items->push([
                    'id' => 'medication-'.$item['id'],
                    'model_id' => $item['id'],
                    'kind' => 'medication',
                    'title' => $item['label'],
                    'source' => 'Health',
                    'why' => $item['overdue'] ? 'Medication is overdue and should be acknowledged.' : 'Medication is pending for today.',
                    'time' => $item['scheduled_for_local'] ?? $item['schedule_time'] ?? null,
                    'urgency' => $item['overdue'] ? 'critical' : 'high',
                    'action_label' => 'Open health',
                    'href' => route('health.index', [], false),
                ]);
            });

        collect($waitingItems)->take(2)->each(fn (array $item) => $items->push([
            'id' => $item['id'],
            'model_id' => $item['model_id'] ?? null,
            'kind' => $item['kind'],
            'title' => $item['title'],
            'source' => $item['source'],
            'why' => $item['reason'],
            'time' => $item['age'],
            'urgency' => 'critical',
            'action_label' => $item['primary_action'],
            'href' => $item['href'],
        ]));

        $this->orderedAttentionTasks($groups)
            ->filter(fn (Task $task) => $this->taskUrgency($task) !== 'low')
            ->each(fn (Task $task) => $items->push([
                'id' => 'task-'.$task->id,
                'model_id' => $task->id,
                'kind' => 'task',
                'title' => $task->title,
                'source' => $this->taskSource($task),
                'why' => $this->taskReason($task),
                'time' => $task->due_date?->toDateString() ?? 'No due date',
                'urgency' => $this->taskUrgency($task),
                'action_label' => 'Open task',
                'href' => route('tasks.show', $task, false),
            ]));

        return $items
            ->unique('id')
            ->sortBy(fn (array $item) => ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3][$item['urgency']] ?? 4)
            ->take(3)
            ->values()
            ->all();
    }

    private function waitingItems(User $user): array
    {
        $approvals = Approval::query()
            ->with(['project:id,name', 'portfolio:id,name', 'area:id,name', 'task:id,title'])
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (Approval $approval) => [
                'id' => 'approval-'.$approval->id,
                'model_id' => $approval->id,
                'kind' => 'approval',
                'title' => $approval->title,
                'source' => $this->objectSource($approval) ?: 'Approval',
                'reason' => 'Approval is required before work can continue.',
                'age' => $this->age($approval->created_at),
                'primary_action' => 'Approve',
                'secondary_action' => 'Reject',
                'href' => route('approvals.index', [], false),
            ]);

        $development = $this->developmentJobs()
            ->whereIn('status', self::WAITING_DEVELOPMENT_STATUSES)
            ->take(6)
            ->map(fn (MiriamDevelopmentJob $job) => [
                'id' => 'codex-job-'.$job->id,
                'model_id' => $job->id,
                'kind' => 'codex',
                'title' => $job->title,
                'source' => $job->managedApp?->name ?? 'Codex / Development',
                'reason' => $this->jobWaitingReason($job),
                'age' => $this->age($job->updated_at),
                'primary_action' => $job->status === 'waiting_for_approval' ? 'Review' : 'Open details',
                'secondary_action' => 'Snooze',
                'href' => route('today.index', [], false).'#codex-workstream',
            ]);

        $waiting = WaitingItem::query()
            ->with(['project:id,name', 'portfolio:id,name', 'area:id,name', 'task:id,title'])
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->where(function ($query): void {
                $query->whereNull('follow_up_date')
                    ->orWhereDate('follow_up_date', '<=', $this->clock->todayString());
            })
            ->latest()
            ->limit(6)
            ->get()
            ->map(fn (WaitingItem $item) => [
                'id' => 'waiting-'.$item->id,
                'model_id' => $item->id,
                'kind' => 'waiting',
                'title' => $item->title,
                'source' => $this->objectSource($item) ?: 'Waiting',
                'reason' => $item->waiting_on ? "Waiting on {$item->waiting_on}." : 'Needs your follow-up or reply.',
                'age' => $this->age($item->created_at),
                'primary_action' => 'Open details',
                'secondary_action' => 'Snooze',
                'href' => route('waiting.index', [], false),
            ]);

        $agentOutputs = AgentOutput::query()
            ->with(['run.agent'])
            ->whereHas('run', fn ($query) => $query->where('user_id', $user->id))
            ->where('status', AgentOutput::STATUS_NEEDS_REVIEW)
            ->whereNotNull('sent_to_today_at')
            ->latest('sent_to_today_at')
            ->limit(8)
            ->get()
            ->map(function (AgentOutput $output): array {
                $run = $output->run;
                $rootRunId = $run?->parent_run_id ?: $run?->id;
                $context = $output->context_label ?: $run?->context_label ?: 'Miriam Agent OS';

                return [
                    'id' => 'agent-output-'.$output->id,
                    'model_id' => $output->id,
                    'kind' => 'agent_output',
                    'title' => $output->title ?: $output->generated_task_title,
                    'source' => $output->agent_name ?: $run?->agent?->name ?: 'Miriam Agent OS',
                    'reason' => $context.' output needs approval before any action is taken.',
                    'age' => $this->age($output->sent_to_today_at ?? $output->created_at),
                    'primary_action' => 'Approve',
                    'secondary_action' => 'Reject',
                    'href' => route('agents.orchestrator.index', ['run' => $rootRunId], false),
                ];
            });

        return collect($approvals)
            ->merge(collect($development))
            ->merge(collect($agentOutputs))
            ->merge(collect($waiting))
            ->sortBy(fn (array $item) => $item['kind'] === 'approval' ? 0 : 1)
            ->values()
            ->all();
    }

    private function codexWorkstream(Collection $jobs): array
    {
        $available = $this->developmentModuleAvailable();

        return [
            // Honest state, not an implied pipeline: when the runner services
            // are absent nothing can start, whatever rows exist in the table.
            'available' => $available,
            'unavailable_reason' => $available
                ? null
                : 'The Codex runner is not installed in this build. Nothing can start, stop, or deploy from here.',
            'status_label' => ! $available
                ? 'Codex not available'
                : ($jobs->isEmpty()
                    ? 'Codex idle'
                    : ($jobs->whereIn('status', self::BLOCKED_DEVELOPMENT_STATUSES)->isNotEmpty() ? 'Codex blocked' : 'Codex active')),
            'jobs' => $jobs->take(6)->map(fn (MiriamDevelopmentJob $job) => [
                'id' => $job->id,
                'title' => $job->title,
                'app' => $job->managedApp?->name ?? 'Unassigned app',
                'status' => $job->status,
                'phase' => $job->currentPhase?->title ?? $job->phaseRuns->sortByDesc('updated_at')->first()?->phase?->title ?? 'No current phase',
                'last_result' => $this->jobLastResult($job),
                'blocker' => $job->failures->firstWhere('resolved_at', null)?->title ?? $job->error_message,
                'next_action' => $this->jobNextAction($job),
                'updated_at' => $this->age($job->updated_at),
            ])->values()->all(),
        ];
    }

    private function products(User $user): array
    {
        $tasks = Task::query()
            ->with(['project:id,name', 'portfolio:id,name', 'area:id,name', 'workspace:id,name'])
            ->where(function ($query) use ($user): void {
                $query->where('assignee_id', $user->id)
                    ->orWhere('reporter_id', $user->id);
            })
            ->active()
            ->get();

        return collect(self::PRODUCT_BUCKETS)
            ->map(fn (array $bucket, string $key) => $this->productCard($key, $bucket, $tasks))
            ->values()
            ->all();
    }

    private function productCard(string $key, array $bucket, Collection $tasks): array
    {
        $matched = $tasks->filter(fn (Task $task) => $this->taskMatchesBucket($task, $bucket['needles']));
        $urgent = $matched->filter(fn (Task $task) => in_array($this->taskUrgency($task), ['critical', 'high'], true));
        $blocked = $matched->first(fn (Task $task) => $task->status === 'blocked' || $task->task_type === 'blocker');
        $next = $matched
            ->sortBy(fn (Task $task) => $this->taskSortScore($task))
            ->first();

        return [
            'key' => $key,
            'label' => $bucket['label'],
            'urgent_count' => $urgent->count(),
            'open_count' => $matched->count(),
            'next_action' => $next?->title ?? 'No active task',
            'blocker' => $blocked?->title,
            'last_movement' => $matched->max('updated_at')?->diffForHumans() ?? 'No recent movement',
            // The bucket is matched on project/portfolio/area names, which no
            // task-title search reproduces. Link to the real task list rather
            // than to a search that is guaranteed to return nothing.
            'href' => route('tasks.index', [], false),
        ];
    }

    private function blockedItems(User $user, Collection $groups, Collection $failures): array
    {
        $taskItems = $groups->get('overdue', collect())
            ->merge($groups->get('blocked', collect()))
            ->unique('id')
            ->take(10)
            ->map(fn (Task $task) => [
                'id' => 'task-'.$task->id,
                'title' => $task->title,
                'owner' => $task->assignee?->name ?? 'Me',
                'source' => $this->taskSource($task),
                'blocked_reason' => $task->status === 'blocked' ? ($task->description ?: 'Marked blocked') : 'Overdue',
                'age' => $task->due_date ? $this->age($task->due_date) : $this->age($task->updated_at),
                'suggested_next_action' => $task->status === 'blocked' ? 'Name the unblocker' : 'Complete, reschedule, or delegate',
                'href' => route('tasks.show', $task, false),
                'tone' => $task->status === 'blocked' ? 'blocked' : 'overdue',
            ]);

        $objectItems = Blocker::query()
            ->with(['project:id,name', 'portfolio:id,name', 'area:id,name'])
            ->where('user_id', $user->id)
            ->where('status', 'open')
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (Blocker $blocker) => [
                'id' => 'blocker-'.$blocker->id,
                'title' => $blocker->title,
                'owner' => 'Me',
                'source' => $this->objectSource($blocker) ?: 'Blocker',
                'blocked_reason' => $blocker->description ?: $blocker->severity,
                'age' => $this->age($blocker->created_at),
                'suggested_next_action' => 'Resolve blocker',
                'href' => route('blockers.index', [], false),
                'tone' => 'blocked',
            ]);

        $failureItems = $failures->take(6)->map(fn (MiriamDevelopmentFailure $failure) => [
            'id' => 'codex-failure-'.$failure->id,
            'title' => $failure->title,
            'owner' => $failure->runnerAgent?->name ?? 'Codex',
            'source' => $failure->job?->managedApp?->name ?? 'Codex / Development',
            'blocked_reason' => $failure->summary ?: $failure->failure_type,
            'age' => $this->age($failure->created_at),
            'suggested_next_action' => $failure->can_auto_fix ? 'Apply fix' : 'Manual review',
            'href' => route('today.index', [], false).'#codex-workstream',
            'tone' => 'blocked',
        ]);

        return collect($taskItems)
            ->merge(collect($objectItems))
            ->merge(collect($failureItems))
            ->values()
            ->all();
    }

    private function laterBacklog(Collection $groups): array
    {
        return $groups->get('upcoming', collect())
            ->merge($groups->get('no_due_date', collect()))
            ->unique('id')
            ->filter(fn (Task $task) => $this->taskUrgency($task) === 'low' || $this->taskUrgency($task) === 'medium')
            ->take(8)
            ->map(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'source' => $this->taskSource($task),
                'status' => $task->status,
                'priority' => $task->priority,
                'due_date' => $task->due_date?->toDateString(),
                'href' => route('tasks.show', $task, false),
            ])
            ->values()
            ->all();
    }

    private function emptyState(array $doThisNow, array $medication, Collection $jobs): array
    {
        return [
            'show' => count($doThisNow) === 0,
            'title' => 'No fires right now',
            'next_action' => 'Clear your Inbox, or pick one task from the list and give it the next hour.',
            'codex_status' => $jobs->isEmpty() ? 'Codex idle' : 'Codex has active work.',
            'medication_status' => $medication['status_label'] ?? 'Medication unknown',
        ];
    }

    /**
     * The Codex / development pipeline is only real when the services that
     * drive it are present. They are not in this build, so Today must say so
     * instead of implying a pipeline that cannot start.
     */
    public function developmentModuleAvailable(): bool
    {
        foreach (self::DEVELOPMENT_MODULE_CLASSES as $class) {
            if (! class_exists($class)) {
                return false;
            }
        }

        return true;
    }

    private function developmentJobs(): Collection
    {
        return MiriamDevelopmentJob::query()
            ->with([
                'managedApp:id,name,slug',
                'runnerAgent:id,name,status,last_seen_at',
                'currentPhase:id,title,phase_key',
                'phaseRuns' => fn ($query) => $query->with('phase:id,title,phase_key')->latest()->limit(3),
                'failures' => fn ($query) => $query->with('runnerAgent:id,name')->whereNull('resolved_at')->latest(),
            ])
            ->whereIn('status', self::ACTIVE_DEVELOPMENT_STATUSES)
            ->latest('updated_at')
            ->limit(12)
            ->get();
    }

    private function developmentFailures(): Collection
    {
        return MiriamDevelopmentFailure::query()
            ->with(['job.managedApp:id,name,slug', 'runnerAgent:id,name'])
            ->whereNull('resolved_at')
            ->whereIn('status', ['open', 'fix_requested', 'fixing', 'manual_attention_required', 'failed'])
            ->latest()
            ->limit(12)
            ->get();
    }

    private function orderedAttentionTasks(Collection $groups): Collection
    {
        return collect()
            ->merge($groups->get('overdue', collect()))
            ->merge($groups->get('due_today', collect()))
            ->merge($groups->get('scheduled_today', collect()))
            ->unique('id')
            ->sortBy(fn (Task $task) => $this->taskSortScore($task))
            ->values();
    }

    private function activeMedicationItems(array $medication): Collection
    {
        return collect($medication['items'] ?? [])
            ->filter(fn (array $item) => ! in_array($item['status'] ?? null, MedicationReminderService::ACKNOWLEDGED_STATUSES, true));
    }

    private function taskUrgency(Task $task): string
    {
        if ($task->status === 'blocked' || in_array($task->task_type, ['approval', 'blocker'], true)) {
            return 'critical';
        }

        $today = $this->clock->todayString();

        if ($task->due_date && $task->due_date->toDateString() < $today) {
            return 'critical';
        }

        if ($task->due_date?->toDateString() === $today || in_array($task->priority, ['urgent', 'high'], true)) {
            return 'high';
        }

        if ($task->due_date && $task->due_date->toDateString() <= $this->clock->dateString(7)) {
            return 'medium';
        }

        return 'low';
    }

    private function taskSortScore(Task $task): int
    {
        $urgency = ['critical' => 0, 'high' => 100, 'medium' => 200, 'low' => 300][$this->taskUrgency($task)] ?? 400;
        $priority = ['urgent' => 0, 'high' => 10, 'medium' => 20, 'low' => 30][$task->priority] ?? 40;

        return $urgency + $priority;
    }

    private function taskReason(Task $task): string
    {
        if ($task->status === 'blocked') {
            return 'This is blocked and needs an unblocker.';
        }

        if ($task->due_date && $task->due_date->toDateString() < $this->clock->todayString()) {
            return 'This is overdue.';
        }

        if ($task->due_date?->toDateString() === $this->clock->todayString()) {
            return 'This is due today.';
        }

        if (in_array($task->priority, ['urgent', 'high'], true)) {
            return 'This is high-priority work.';
        }

        return 'This is the next scheduled task.';
    }

    private function taskSource(Task $task): string
    {
        return collect([
            $task->portfolio?->name,
            $task->project?->name,
            $task->area?->name,
            $task->workspace?->name,
        ])->filter()->first() ?? 'Task';
    }

    private function objectSource($item): ?string
    {
        return collect([
            $item->portfolio?->name,
            $item->project?->name,
            $item->area?->name,
            $item->task?->title,
        ])->filter()->first();
    }

    private function taskMatchesBucket(Task $task, array $needles): bool
    {
        $haystack = str(collect([
            $task->title,
            $task->description,
            $task->context,
            $task->project?->name,
            $task->portfolio?->name,
            $task->area?->name,
            $task->workspace?->name,
        ])->filter()->implode(' '))->lower()->toString();

        return collect($needles)->contains(fn (string $needle) => str_contains($haystack, $needle));
    }

    private function jobWaitingReason(MiriamDevelopmentJob $job): string
    {
        return match ($job->status) {
            'waiting_for_approval' => 'Codex output or release decision needs your review.',
            'waiting_for_manual_fix' => 'Codex is blocked on a manual fix.',
            'paused' => 'Codex is paused until you resume it.',
            default => 'Codex needs your decision.',
        };
    }

    private function jobLastResult(MiriamDevelopmentJob $job): string
    {
        $phase = $job->phaseRuns->sortByDesc('updated_at')->first();

        if ($job->status === 'failed') {
            return 'Failed';
        }

        if ($phase?->status) {
            return $phase->status;
        }

        return $job->status;
    }

    private function jobNextAction(MiriamDevelopmentJob $job): string
    {
        return match ($job->status) {
            'queued', 'waiting_for_runner' => 'Wait for runner',
            'preparing', 'running' => 'Monitor progress',
            'waiting_for_approval' => 'Review and approve',
            'waiting_for_manual_fix' => 'Manual fix required',
            'paused' => 'Resume or cancel',
            'blocked', 'failed' => 'Review failure',
            default => 'Open workstream',
        };
    }

    private function age($date): string
    {
        return $date ? $date->diffForHumans() : 'No date';
    }
}
