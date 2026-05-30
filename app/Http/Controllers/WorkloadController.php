<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class WorkloadController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $workspaceIds = $request->user()->workspaces()->pluck('workspaces.id')->all();
        $openTasks = $this->applyTaskFilters($this->workspaceTasks($workspaceIds), $filters)
            ->active()
            ->with(['assignee:id,name,email', 'project:id,name,portfolio_id', 'portfolio:id,name,area_id', 'area:id,name'])
            ->get();

        $assigneeWorkloads = $this->assigneeWorkloads($openTasks, $workspaceIds, $filters);

        return Inertia::render('Workload/Index', [
            'filters' => $filters,
            'options' => $this->options($workspaceIds),
            'summary' => $this->summary($openTasks, $assigneeWorkloads),
            'assigneeWorkloads' => $assigneeWorkloads,
            'unassignedTasks' => $this->unassignedTasks($openTasks),
            'portfolioWorkloads' => $this->portfolioWorkloads($openTasks),
            'projectWorkloads' => $this->projectWorkloads($openTasks),
            'weeklyBuckets' => $this->weeklyBuckets($openTasks),
        ]);
    }

    private function filters(Request $request): array
    {
        return [
            'assignee_id' => $request->string('assignee_id')->toString(),
            'area_id' => $request->string('area_id')->toString(),
            'portfolio_id' => $request->string('portfolio_id')->toString(),
            'project_id' => $request->string('project_id')->toString(),
            'priority' => $request->string('priority')->toString(),
            'due_bucket' => $request->string('due_bucket')->toString(),
        ];
    }

    private function summary(Collection $openTasks, array $assigneeWorkloads): array
    {
        return [
            'total_open_tasks' => $openTasks->count(),
            'total_overdue_tasks' => $openTasks->filter(fn (Task $task) => $this->isOverdue($task))->count(),
            'total_due_this_week' => $openTasks->filter(fn (Task $task) => $this->isDueThisWeek($task))->count(),
            'total_unassigned_tasks' => $openTasks->whereNull('assignee_id')->count(),
            'overloaded_people' => collect($assigneeWorkloads)
                ->reject(fn (array $workload) => $workload['is_unassigned'])
                ->where('classification', 'Overloaded')
                ->count(),
            'available_people' => collect($assigneeWorkloads)
                ->reject(fn (array $workload) => $workload['is_unassigned'])
                ->where('classification', 'Available')
                ->count(),
        ];
    }

    private function assigneeWorkloads(Collection $openTasks, array $workspaceIds, array $filters): array
    {
        $users = User::query()
            ->select(['id', 'name', 'email'])
            ->when($filters['assignee_id'] !== '' && $filters['assignee_id'] !== 'unassigned', fn (Builder $query) => $query->whereKey((int) $filters['assignee_id']))
            ->when($filters['assignee_id'] === '', fn (Builder $query) => $query->where(function (Builder $query) use ($workspaceIds, $openTasks) {
                $assigneeIds = $openTasks->pluck('assignee_id')->filter()->unique()->values()->all();

                $query->when($workspaceIds !== [], fn (Builder $query) => $query->whereHas('workspaces', fn (Builder $workspaceQuery) => $workspaceQuery->whereIn('workspaces.id', $workspaceIds)));

                if ($assigneeIds !== []) {
                    $query->orWhereIn('id', $assigneeIds);
                }
            }))
            ->when($filters['assignee_id'] === 'unassigned', fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->orderBy('name')
            ->get();

        $workloads = $users
            ->map(fn (User $user) => $this->assigneeWorkloadResource(
                (string) $user->id,
                $user->name,
                $openTasks->where('assignee_id', $user->id),
                false
            ));

        if ($openTasks->whereNull('assignee_id')->isNotEmpty()) {
            $workloads->push($this->assigneeWorkloadResource('unassigned', 'Unassigned', $openTasks->whereNull('assignee_id'), true));
        }

        return $workloads
            ->sortByDesc('workload_score')
            ->values()
            ->all();
    }

    private function assigneeWorkloadResource(string $id, string $name, Collection $tasks, bool $isUnassigned): array
    {
        $score = $this->workloadScore($tasks);

        return [
            'id' => $id,
            'name' => $name,
            'initial' => strtoupper(substr($name, 0, 1)),
            'is_unassigned' => $isUnassigned,
            'total_open_tasks' => $tasks->count(),
            'overdue_tasks' => $tasks->filter(fn (Task $task) => $this->isOverdue($task))->count(),
            'due_today' => $tasks->filter(fn (Task $task) => $this->isDueToday($task))->count(),
            'due_this_week' => $tasks->filter(fn (Task $task) => $this->isDueThisWeek($task))->count(),
            'urgent_open_tasks' => $tasks->where('priority', 'urgent')->count(),
            'high_priority_open_tasks' => $tasks->where('priority', 'high')->count(),
            'blocked_tasks' => $tasks->where('status', 'blocked')->count(),
            'review_tasks' => $tasks->where('status', 'review')->count(),
            'no_due_date_tasks' => $tasks->whereNull('due_date')->count(),
            'workload_score' => $score,
            'classification' => $this->classification($tasks->count(), $score),
            'pressure' => min(100, $score * 4),
            'href' => $isUnassigned
                ? route('workload.index', ['assignee_id' => 'unassigned'], false)
                : route('workload.index', ['assignee_id' => $id], false),
        ];
    }

    private function unassignedTasks(Collection $openTasks): array
    {
        return $openTasks
            ->whereNull('assignee_id')
            ->sortBy(fn (Task $task) => $this->priorityRank($task->priority).'-'.($task->due_date?->toDateString() ?? '9999-12-31').'-'.$task->id)
            ->take(10)
            ->map(fn (Task $task) => $this->taskResource($task))
            ->values()
            ->all();
    }

    private function portfolioWorkloads(Collection $openTasks): array
    {
        return $openTasks
            ->filter(fn (Task $task) => $task->portfolio_id)
            ->groupBy('portfolio_id')
            ->map(function (Collection $tasks): array {
                $portfolio = $tasks->first()->portfolio;
                $score = $this->workloadScore($tasks);
                $topAssignee = $tasks
                    ->groupBy(fn (Task $task) => $task->assignee?->name ?? 'Unassigned')
                    ->map(fn (Collection $assigneeTasks, string $name) => ['name' => $name, 'count' => $assigneeTasks->count()])
                    ->sortByDesc('count')
                    ->first();

                return [
                    'id' => $portfolio->id,
                    'name' => $portfolio->name,
                    'area' => $portfolio->area?->only(['id', 'name']),
                    'open_tasks' => $tasks->count(),
                    'overdue_tasks' => $tasks->filter(fn (Task $task) => $this->isOverdue($task))->count(),
                    'urgent_high_open_tasks' => $tasks->whereIn('priority', ['urgent', 'high'])->count(),
                    'top_assignee' => $topAssignee,
                    'pressure' => min(100, $score * 4),
                    'href' => route('portfolios.show', $portfolio->id, false),
                ];
            })
            ->sortByDesc('open_tasks')
            ->values()
            ->all();
    }

    private function projectWorkloads(Collection $openTasks): array
    {
        return $openTasks
            ->filter(fn (Task $task) => $task->project_id)
            ->groupBy('project_id')
            ->map(function (Collection $tasks): array {
                $project = $tasks->first()->project;
                $score = $this->workloadScore($tasks);

                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'portfolio' => $project->portfolio?->only(['id', 'name']),
                    'open_tasks' => $tasks->count(),
                    'overdue_tasks' => $tasks->filter(fn (Task $task) => $this->isOverdue($task))->count(),
                    'due_this_week' => $tasks->filter(fn (Task $task) => $this->isDueThisWeek($task))->count(),
                    'unassigned_tasks' => $tasks->whereNull('assignee_id')->count(),
                    'workload_score' => $score,
                    'pressure' => min(100, $score * 4),
                    'href' => route('projects.show', $project->id, false),
                ];
            })
            ->sortByDesc('workload_score')
            ->values()
            ->all();
    }

    private function weeklyBuckets(Collection $openTasks): array
    {
        $buckets = [
            'overdue' => ['label' => 'Overdue', 'tasks' => $openTasks->filter(fn (Task $task) => $this->isOverdue($task))],
            'today' => ['label' => 'Today', 'tasks' => $openTasks->filter(fn (Task $task) => $this->isDueToday($task))],
            'tomorrow' => ['label' => 'Tomorrow', 'tasks' => $openTasks->filter(fn (Task $task) => $this->isTomorrow($task))],
            'next_7_days' => ['label' => 'Next 7 days', 'tasks' => $openTasks->filter(fn (Task $task) => $this->isNextSevenDays($task))],
            'no_due_date' => ['label' => 'No due date', 'tasks' => $openTasks->whereNull('due_date')],
        ];

        return collect($buckets)
            ->map(fn (array $bucket, string $key) => [
                'key' => $key,
                'label' => $bucket['label'],
                'count' => $bucket['tasks']->count(),
                'tasks' => $bucket['tasks']
                    ->sortBy(fn (Task $task) => $this->priorityRank($task->priority).'-'.($task->due_date?->toDateString() ?? '9999-12-31').'-'.$task->id)
                    ->take(5)
                    ->map(fn (Task $task) => $this->taskResource($task))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    private function options(array $workspaceIds): array
    {
        return [
            'users' => User::query()
                ->select(['id', 'name'])
                ->when($workspaceIds !== [], fn (Builder $query) => $query->whereHas('workspaces', fn (Builder $workspaceQuery) => $workspaceQuery->whereIn('workspaces.id', $workspaceIds)))
                ->orderBy('name')
                ->get(),
            'areas' => Area::query()->select(['id', 'name'])->orderBy('position')->orderBy('name')->get(),
            'portfolios' => $this->workspacePortfolios($workspaceIds)->select(['id', 'area_id', 'name'])->orderBy('name')->get(),
            'projects' => $this->workspaceProjects($workspaceIds)->select(['id', 'portfolio_id', 'area_id', 'name'])->orderBy('name')->get(),
            'priorities' => Task::PRIORITIES,
            'dueBuckets' => [
                ['value' => 'overdue', 'label' => 'Overdue'],
                ['value' => 'today', 'label' => 'Today'],
                ['value' => 'tomorrow', 'label' => 'Tomorrow'],
                ['value' => 'next_7_days', 'label' => 'Next 7 days'],
                ['value' => 'no_due_date', 'label' => 'No due date'],
            ],
        ];
    }

    private function applyTaskFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['assignee_id'] !== '', function (Builder $query) use ($filters) {
                return $filters['assignee_id'] === 'unassigned'
                    ? $query->whereNull('assignee_id')
                    : $query->where('assignee_id', (int) $filters['assignee_id']);
            })
            ->when($filters['area_id'] !== '', fn (Builder $query) => $query->where('area_id', (int) $filters['area_id']))
            ->when($filters['portfolio_id'] !== '', fn (Builder $query) => $query->where('portfolio_id', (int) $filters['portfolio_id']))
            ->when($filters['project_id'] !== '', fn (Builder $query) => $query->where('project_id', (int) $filters['project_id']))
            ->when($filters['priority'] !== '', fn (Builder $query) => $query->where('priority', $filters['priority']))
            ->when($filters['due_bucket'] !== '', fn (Builder $query) => $this->applyDueBucket($query, $filters['due_bucket']));
    }

    private function applyDueBucket(Builder $query, string $bucket): Builder
    {
        return match ($bucket) {
            'overdue' => $query->overdue(),
            'today' => $query->dueToday(),
            'tomorrow' => $query->whereDate('due_date', now()->addDay()->toDateString()),
            'next_7_days' => $query->whereBetween('due_date', [now()->addDays(2)->toDateString(), now()->addDays(7)->toDateString()]),
            'no_due_date' => $query->whereNull('due_date'),
            default => $query,
        };
    }

    private function workspaceTasks(array $workspaceIds): Builder
    {
        return Task::query()->when(
            $workspaceIds !== [],
            fn (Builder $query) => $query->whereIn('workspace_id', $workspaceIds),
            fn (Builder $query) => $query->whereRaw('1 = 0'),
        );
    }

    private function workspaceProjects(array $workspaceIds): Builder
    {
        return Project::query()->when(
            $workspaceIds !== [],
            fn (Builder $query) => $query->whereIn('workspace_id', $workspaceIds),
            fn (Builder $query) => $query->whereRaw('1 = 0'),
        );
    }

    private function workspacePortfolios(array $workspaceIds): Builder
    {
        return Portfolio::query()->when(
            $workspaceIds !== [],
            fn (Builder $query) => $query->whereIn('workspace_id', $workspaceIds),
            fn (Builder $query) => $query->whereRaw('1 = 0'),
        );
    }

    private function workloadScore(Collection $tasks): int
    {
        return $tasks->sum(function (Task $task): int {
            $score = match ($task->priority) {
                'urgent' => 5,
                'high' => 3,
                'medium' => 2,
                'low' => 1,
                default => 0,
            };

            if ($this->isOverdue($task)) {
                $score += 2;
            }

            if ($this->isDueToday($task)) {
                $score += 1;
            }

            if ($task->status === 'blocked') {
                $score += 2;
            }

            return $score;
        });
    }

    private function classification(int $taskCount, int $score): string
    {
        return match (true) {
            $taskCount === 0 && $score === 0 => 'Available',
            $score <= 10 => 'Healthy',
            $score <= 20 => 'Busy',
            default => 'Overloaded',
        };
    }

    private function taskResource(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'due_date' => $task->due_date?->toDateString(),
            'project' => $task->project?->only(['id', 'name']),
            'portfolio' => $task->portfolio?->only(['id', 'name']),
            'area' => $task->area?->only(['id', 'name']),
            'href' => route('tasks.show', $task->id, false),
        ];
    }

    private function isOverdue(Task $task): bool
    {
        return $task->due_date !== null && $task->due_date->toDateString() < now()->toDateString();
    }

    private function isDueToday(Task $task): bool
    {
        return $task->due_date?->toDateString() === now()->toDateString();
    }

    private function isTomorrow(Task $task): bool
    {
        return $task->due_date?->toDateString() === now()->addDay()->toDateString();
    }

    private function isNextSevenDays(Task $task): bool
    {
        return $task->due_date !== null
            && $task->due_date->toDateString() >= now()->addDays(2)->toDateString()
            && $task->due_date->toDateString() <= now()->addDays(7)->toDateString();
    }

    private function isDueThisWeek(Task $task): bool
    {
        return $task->due_date !== null
            && $task->due_date->toDateString() >= now()->toDateString()
            && $task->due_date->toDateString() <= now()->endOfWeek()->toDateString();
    }

    private function priorityRank(?string $priority): int
    {
        return match ($priority) {
            'urgent' => 1,
            'high' => 2,
            'medium' => 3,
            'low' => 4,
            default => 5,
        };
    }
}
