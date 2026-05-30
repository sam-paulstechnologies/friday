<?php

namespace App\Services\TaskReview;

use App\Models\Area;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PrioritizationReviewService
{
    public const BUCKETS = [
        'now' => 'Now',
        'this_week' => 'This Week',
        'this_month' => 'This Month',
        'later' => 'Later',
        'waiting' => 'Waiting / Delegated',
        'archive_candidate' => 'Drop / Archive Candidate',
    ];

    public function build(array $filters = []): array
    {
        $allRows = Task::query()
            ->with([
                'area:id,name',
                'portfolio:id,name,area_id',
                'project:id,name,portfolio_id',
                'assignee:id,name,email',
                'reporter:id,name,email',
            ])
            ->orderByRaw("case priority when 'urgent' then 1 when 'high' then 2 when 'medium' then 3 when 'low' then 4 else 5 end")
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->map(fn (Task $task): array => $this->taskRow($task))
            ->values();

        $filteredRows = $this->applyFilters($allRows, $filters)->values();

        return [
            'generated_at' => now()->toDateTimeString(),
            'filters' => $this->normalizedFilters($filters),
            'options' => $this->options($allRows),
            'summary' => $this->summary($allRows),
            'buckets' => $this->bucketGroups($filteredRows),
            'tasks' => $filteredRows->all(),
            'intentLens' => $this->intentLens($allRows),
            'bucketLabels' => self::BUCKETS,
            'statuses' => Task::STATUSES,
            'priorities' => Task::PRIORITIES,
        ];
    }

    public function bucketUpdates(string $bucket): array
    {
        $today = now()->toDateString();

        return match ($bucket) {
            'now' => ['priority' => 'urgent', 'due_date' => $today],
            'this_week' => ['priority' => 'high', 'due_date' => now()->addDays(7)->toDateString()],
            'this_month' => ['priority' => 'medium', 'due_date' => now()->addDays(30)->toDateString()],
            'later' => ['priority' => 'low', 'due_date' => null],
            'waiting' => ['status' => 'blocked'],
            'archive_candidate' => ['status' => 'archived'],
            default => [],
        };
    }

    public function apply(Collection $tasks, array $changes, ?int $userId): int
    {
        $updated = 0;

        foreach ($tasks as $task) {
            $taskChanges = [];
            $oldValues = [];
            $newValues = [];

            foreach (['priority', 'status', 'due_date'] as $field) {
                if (! array_key_exists($field, $changes)) {
                    continue;
                }

                $oldValue = $this->normalizeValue($task->{$field});
                $newValue = $this->normalizeValue($changes[$field]);

                if ($oldValue === $newValue) {
                    continue;
                }

                $taskChanges[$field] = $changes[$field];
                $oldValues[$field] = $oldValue;
                $newValues[$field] = $newValue;
            }

            if ($taskChanges === []) {
                continue;
            }

            if (($taskChanges['status'] ?? null) === 'completed') {
                $taskChanges['completed_at'] = $task->completed_at ?? now();
            } elseif (array_key_exists('status', $taskChanges)) {
                $taskChanges['completed_at'] = null;
            }

            $task->update($taskChanges);
            $this->logActivity($task, $userId, $oldValues, $newValues);
            $updated++;
        }

        return $updated;
    }

    public function taskRow(Task $task): array
    {
        $dueDate = $task->due_date?->toDateString();
        [$bucket, $reason] = $this->suggestion($task);

        return [
            'id' => $task->id,
            'area_id' => $task->area_id,
            'portfolio_id' => $task->portfolio_id,
            'project_id' => $task->project_id,
            'area' => $task->area?->name ?? 'No area',
            'portfolio' => $task->portfolio?->name ?? 'No portfolio',
            'project' => $task->project?->name ?? 'No project',
            'title' => $task->title,
            'description' => $this->shortSummary($task->description),
            'status' => $task->status,
            'priority' => $task->priority,
            'due_date' => $dueDate,
            'due_bucket' => $this->dueBucket($task),
            'assignee' => $task->assignee?->name ?? $task->assignee?->email ?? 'Unassigned',
            'reporter' => $task->reporter?->name ?? $task->reporter?->email ?? 'No reporter',
            'suggested_bucket' => $bucket,
            'suggested_bucket_label' => self::BUCKETS[$bucket],
            'suggested_reason' => $reason,
            'is_open' => $this->isOpen($task->status),
            'is_overdue' => $this->isOpen($task->status) && $dueDate !== null && $dueDate < now()->toDateString(),
            'is_due_this_week' => $this->isOpen($task->status) && $dueDate !== null && $dueDate >= now()->toDateString() && $dueDate <= now()->addDays(7)->toDateString(),
        ];
    }

    private function suggestion(Task $task): array
    {
        $text = $this->taskText($task);
        $due = $task->due_date?->toDateString();
        $today = now()->toDateString();
        $isOpen = $this->isOpen($task->status);

        if ($this->isWaitingCandidate($text)) {
            return ['waiting', 'Next action appears to depend on another person, team, client, dealer, or support channel.'];
        }

        if ($task->status === 'completed' && in_array($task->priority, ['urgent', 'high'], true)) {
            return ['archive_candidate', 'Completed task still carries urgent/high priority and should be reviewed for cleanup.'];
        }

        if ($isOpen && $this->isArchiveCandidate($task, $text)) {
            return ['archive_candidate', 'Low urgency, stale, duplicated, or weak-action task that may need closing or archiving.'];
        }

        if ($isOpen && (
            ($task->priority === 'urgent' && ($due === null || $due <= $today))
            || $this->containsAny($text, ['launch', 'blocker', 'deploy', 'deployment', 'critical', 'production', 'go-live'])
            || $this->containsAny($text, ['stellantis gcc', 'stellantis south africa', 'digitas internal', 'south africa'])
        )) {
            return ['now', 'Urgent, due now/overdue, launch-critical, production-critical, or income-protection work.'];
        }

        if ($isOpen && (
            ($due !== null && $due >= $today && $due <= now()->addDays(7)->toDateString())
            || $task->priority === 'high'
            || $this->containsAny($text, ['uae realtor', 'eid mvp', 'sayaraforce', 'major work dashboard', 'dashboard'])
        )) {
            return ['this_week', 'Open task is due this week, high priority, or belongs to an active sprint/work dashboard stream.'];
        }

        if ($isOpen && (
            in_array($task->priority, ['medium', 'high'], true)
            || $this->containsAny($text, ['paul', 'photography', 'career growth', 'linkedin', 'certification', 'certifications'])
        )) {
            return ['this_month', 'Important but not immediate work, brand launch work, or career growth item.'];
        }

        return ['later', 'Low-priority, future enhancement, idea, or non-blocking improvement.'];
    }

    private function applyFilters(Collection $rows, array $filters): Collection
    {
        $filters = $this->normalizedFilters($filters);

        return $rows
            ->when($filters['area_id'] !== '', fn (Collection $rows) => $rows->where('area_id', (int) $filters['area_id']))
            ->when($filters['portfolio_id'] !== '', fn (Collection $rows) => $rows->where('portfolio_id', (int) $filters['portfolio_id']))
            ->when($filters['project_id'] !== '', fn (Collection $rows) => $rows->where('project_id', (int) $filters['project_id']))
            ->when($filters['status'] !== '', fn (Collection $rows) => $rows->where('status', $filters['status']))
            ->when($filters['priority'] !== '', fn (Collection $rows) => $rows->where('priority', $filters['priority']))
            ->when($filters['due_bucket'] !== '', fn (Collection $rows) => $rows->where('due_bucket', $filters['due_bucket']))
            ->when($filters['suggested_bucket'] !== '', fn (Collection $rows) => $rows->where('suggested_bucket', $filters['suggested_bucket']))
            ->when($filters['search'] !== '', fn (Collection $rows) => $rows->filter(fn (array $task): bool => Str::of($task['title'].' '.$task['description'].' '.$task['area'].' '.$task['portfolio'].' '.$task['project'])->lower()->contains(Str::lower($filters['search']))));
    }

    private function summary(Collection $rows): array
    {
        $open = $rows->where('is_open', true);

        return [
            'total_open_tasks' => $open->count(),
            'urgent_high_open' => $open->whereIn('priority', ['urgent', 'high'])->count(),
            'overdue' => $rows->where('is_overdue', true)->count(),
            'due_this_week' => $rows->where('is_due_this_week', true)->count(),
            'no_due_date' => $open->whereNull('due_date')->count(),
            'waiting_delegated_candidates' => $rows->where('suggested_bucket', 'waiting')->count(),
            'archive_candidates' => $rows->where('suggested_bucket', 'archive_candidate')->count(),
        ];
    }

    private function bucketGroups(Collection $rows): array
    {
        return collect(self::BUCKETS)
            ->map(fn (string $label, string $key): array => [
                'key' => $key,
                'label' => $label,
                'tasks' => $rows->where('suggested_bucket', $key)->values()->all(),
            ])
            ->values()
            ->all();
    }

    private function intentLens(Collection $rows): array
    {
        $lenses = [
            'income_protection' => [
                'label' => 'Income Protection / Work',
                'needles' => ['stellantis gcc', 'stellantis south africa', 'digitas internal', 'south africa'],
            ],
            'eid_build_sprint' => [
                'label' => 'Eid Build Sprint',
                'needles' => ['uae realtor', 'realtor agents', 'eid'],
            ],
            'product_ventures' => [
                'label' => 'Product Ventures',
                'needles' => ['sayaraforce', 'churchforce'],
            ],
            'business_support' => [
                'label' => 'Business Support',
                'needles' => ['paul', 'photography'],
            ],
            'personal_growth' => [
                'label' => 'Personal Growth',
                'needles' => ['career growth', 'personal foundation', 'linkedin', 'certification'],
            ],
        ];

        return collect($lenses)
            ->map(function (array $lens, string $key) use ($rows): array {
                $tasks = $rows->filter(fn (array $task): bool => $this->containsAny(Str::lower($task['area'].' '.$task['portfolio'].' '.$task['project'].' '.$task['title']), $lens['needles']));
                $open = $tasks->where('is_open', true);

                return [
                    'key' => $key,
                    'label' => $lens['label'],
                    'open_tasks' => $open->count(),
                    'urgent_high' => $open->whereIn('priority', ['urgent', 'high'])->count(),
                    'overdue' => $tasks->where('is_overdue', true)->count(),
                    'due_this_week' => $tasks->where('is_due_this_week', true)->count(),
                    'top_focus_tasks' => $open
                        ->sortBy(fn (array $task): array => [
                            array_search($task['suggested_bucket'], ['now', 'this_week', 'waiting', 'this_month', 'later', 'archive_candidate'], true) ?: 0,
                            array_search($task['priority'], ['urgent', 'high', 'medium', 'low'], true) ?: 0,
                            $task['due_date'] ?? '9999-12-31',
                        ])
                        ->take(5)
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function options(Collection $rows): array
    {
        return [
            'areas' => Area::query()->select(['id', 'name'])->orderBy('position')->orderBy('name')->get(),
            'portfolios' => Portfolio::query()->select(['id', 'area_id', 'name'])->orderBy('name')->get(),
            'projects' => Project::query()->select(['id', 'portfolio_id', 'name'])->orderBy('name')->get(),
            'statuses' => Task::STATUSES,
            'priorities' => Task::PRIORITIES,
            'dueBuckets' => $rows->pluck('due_bucket')->unique()->sort()->values()->map(fn (string $bucket): array => ['value' => $bucket, 'label' => $bucket])->all(),
            'suggestedBuckets' => collect(self::BUCKETS)->map(fn (string $label, string $key): array => ['value' => $key, 'label' => $label])->values()->all(),
        ];
    }

    private function normalizedFilters(array $filters): array
    {
        return [
            'area_id' => (string) ($filters['area_id'] ?? ''),
            'portfolio_id' => (string) ($filters['portfolio_id'] ?? ''),
            'project_id' => (string) ($filters['project_id'] ?? ''),
            'status' => (string) ($filters['status'] ?? ''),
            'priority' => (string) ($filters['priority'] ?? ''),
            'due_bucket' => (string) ($filters['due_bucket'] ?? ''),
            'suggested_bucket' => (string) ($filters['suggested_bucket'] ?? ''),
            'search' => (string) ($filters['search'] ?? ''),
        ];
    }

    private function isArchiveCandidate(Task $task, string $text): bool
    {
        $due = $task->due_date?->toDateString();
        $staleNoDue = $task->due_date === null && $task->priority === 'low' && $this->containsAny($text, ['idea', 'maybe', 'consider', 'nice to have', 'improvement']);

        return ($task->priority === 'low' && $due !== null && $due < now()->toDateString())
            || $staleNoDue
            || $this->containsAny($text, ['duplicate', 'duplicated', 'dupe']);
    }

    private function isWaitingCandidate(string $text): bool
    {
        return $this->containsAny($text, ['awaiting', 'waiting', 'follow up', 'shared with', 'tech team', 'jheel', 'harsh', 'wisam', 'samuel', 'meta support', 'dealer', 'client']);
    }

    private function dueBucket(Task $task): string
    {
        if ($task->due_date === null) {
            return 'No due date';
        }

        $due = $task->due_date->toDateString();
        $today = now()->toDateString();

        if ($this->isOpen($task->status) && $due < $today) {
            return 'Overdue';
        }

        if ($due === $today) {
            return 'Due today';
        }

        if ($due > $today && $due <= now()->addDays(7)->toDateString()) {
            return 'Due this week';
        }

        if ($due > now()->addDays(7)->toDateString() && $due <= now()->addDays(30)->toDateString()) {
            return 'Due this month';
        }

        return $due > $today ? 'Future' : 'Past completed';
    }

    private function taskText(Task $task): string
    {
        return Str::lower(implode(' ', [
            $task->area?->name,
            $task->portfolio?->name,
            $task->project?->name,
            $task->title,
            $task->description,
        ]));
    }

    private function containsAny(string $text, array $needles): bool
    {
        return Str::of($text)->lower()->contains($needles);
    }

    private function shortSummary(?string $description): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $description)));

        return $text === '' ? '' : Str::limit($text, 120);
    }

    private function isOpen(string $status): bool
    {
        return ! in_array($status, ['completed', 'done', 'archived'], true);
    }

    private function logActivity(Task $task, ?int $userId, array $oldValues, array $newValues): void
    {
        if (! Schema::hasTable('task_activities')) {
            return;
        }

        $task->activities()->create([
            'user_id' => $userId,
            'action' => 'prioritization_review_updated',
            'description' => 'Updated via Prioritization Review Mode',
            'old_value' => json_encode($oldValues),
            'new_value' => json_encode($newValues),
        ]);
    }

    private function normalizeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }
}
