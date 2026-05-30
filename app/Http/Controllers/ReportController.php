<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Goal;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $workspaceIds = $request->user()->workspaces()->pluck('workspaces.id')->all();

        $taskQuery = $this->applyTaskFilters(
            $this->workspaceTasks($workspaceIds),
            $filters
        );

        $projectQuery = $this->applyProjectFilters(
            $this->workspaceProjects($workspaceIds),
            $filters
        );

        $portfolioQuery = $this->applyPortfolioFilters(
            $this->workspacePortfolios($workspaceIds),
            $filters
        );

        return Inertia::render('Reports/Index', [
            'filters' => $filters,
            'options' => $this->options($workspaceIds),
            'summary' => $this->summary($taskQuery, $projectQuery, $portfolioQuery, $filters, $workspaceIds),
            'portfolioMetrics' => $this->portfolioMetrics($portfolioQuery, $filters),
            'projectMetrics' => $this->projectMetrics($projectQuery, $filters),
            'goalMetrics' => $this->goalMetrics($workspaceIds),
            'workloadMetrics' => $this->workloadMetrics($taskQuery, $workspaceIds),
            'taskHealth' => $this->taskHealth($taskQuery),
            'launchReadiness' => $this->launchReadiness($workspaceIds),
            'trends' => $this->trends($taskQuery),
        ]);
    }

    private function filters(Request $request): array
    {
        return [
            'area_id' => $request->string('area_id')->toString(),
            'portfolio_id' => $request->string('portfolio_id')->toString(),
            'status' => $request->string('status')->toString(),
            'priority' => $request->string('priority')->toString(),
            'due_bucket' => $request->string('due_bucket')->toString(),
        ];
    }

    private function summary(Builder $taskQuery, Builder $projectQuery, Builder $portfolioQuery, array $filters, array $workspaceIds): array
    {
        $areaQuery = Area::query()->where('is_active', true);

        if ($filters['area_id'] !== '') {
            $areaQuery->whereKey((int) $filters['area_id']);
        }

        return [
            'total_open_tasks' => (clone $taskQuery)->active()->count(),
            'completed_tasks' => (clone $taskQuery)->where('status', 'completed')->count(),
            'overdue_tasks' => (clone $taskQuery)->active()->overdue()->count(),
            'due_today' => (clone $taskQuery)->active()->dueToday()->count(),
            'due_this_week' => (clone $taskQuery)->active()->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])->count(),
            'total_projects' => (clone $projectQuery)->count(),
            'active_projects' => (clone $projectQuery)->where('status', 'active')->count(),
            'completed_projects' => (clone $projectQuery)->where('status', 'completed')->count(),
            'active_portfolios' => (clone $portfolioQuery)->where('status', 'active')->count(),
            'active_areas' => $areaQuery->count(),
            'active_goals' => Goal::query()
                ->when(
                    $workspaceIds !== [],
                    fn (Builder $query) => $query->whereIn('workspace_id', $workspaceIds),
                    fn (Builder $query) => $query->whereRaw('1 = 0'),
                )
                ->whereNotIn('status', ['completed', 'archived'])
                ->count(),
        ];
    }

    private function portfolioMetrics(Builder $portfolioQuery, array $filters): array
    {
        return $portfolioQuery
            ->with('area:id,name')
            ->withCount([
                'projects as total_projects_count',
                'tasks as total_tasks_count' => fn (Builder $query) => $this->applyTaskFilters($query, $filters),
                'tasks as open_tasks_count' => fn (Builder $query) => $this->applyTaskFilters($query, $filters)->active(),
                'tasks as completed_tasks_count' => fn (Builder $query) => $this->applyTaskFilters($query, $filters)->where('status', 'completed'),
                'tasks as overdue_tasks_count' => fn (Builder $query) => $this->applyTaskFilters($query, $filters)->active()->overdue(),
            ])
            ->orderBy('position')
            ->orderBy('name')
            ->get()
            ->map(fn (Portfolio $portfolio) => $this->portfolioMetricResource($portfolio))
            ->all();
    }

    private function projectMetrics(Builder $projectQuery, array $filters): array
    {
        return $projectQuery
            ->with(['portfolio:id,name', 'area:id,name'])
            ->withCount([
                'tasks as total_tasks_count' => fn (Builder $query) => $this->applyTaskFilters($query, $filters),
                'tasks as open_tasks_count' => fn (Builder $query) => $this->applyTaskFilters($query, $filters)->active(),
                'tasks as completed_tasks_count' => fn (Builder $query) => $this->applyTaskFilters($query, $filters)->where('status', 'completed'),
                'tasks as overdue_tasks_count' => fn (Builder $query) => $this->applyTaskFilters($query, $filters)->active()->overdue(),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
                'health' => $project->health ?: $this->calculatedProjectHealth($project),
                'calculated_health' => $this->calculatedProjectHealth($project),
                'due_date' => $project->due_date?->toDateString(),
                'portfolio' => $project->portfolio?->only(['id', 'name']),
                'area' => $project->area?->only(['id', 'name']),
                'total_tasks' => (int) $project->total_tasks_count,
                'open_tasks' => (int) $project->open_tasks_count,
                'completed_tasks' => (int) $project->completed_tasks_count,
                'overdue_tasks' => (int) $project->overdue_tasks_count,
                'progress' => $this->progress((int) $project->completed_tasks_count, (int) $project->total_tasks_count),
            ])
            ->all();
    }

    private function taskHealth(Builder $taskQuery): array
    {
        return [
            'byStatus' => $this->groupCount($taskQuery, 'status'),
            'byPriority' => $this->groupCount($taskQuery, 'priority'),
            'byDueBucket' => [
                ['label' => 'Overdue', 'count' => (clone $taskQuery)->active()->overdue()->count()],
                ['label' => 'Today', 'count' => (clone $taskQuery)->active()->dueToday()->count()],
                ['label' => 'This week', 'count' => (clone $taskQuery)->active()->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])->count()],
                ['label' => 'No due date', 'count' => (clone $taskQuery)->active()->whereNull('due_date')->count()],
            ],
            'byPortfolio' => (clone $taskQuery)
                ->with('portfolio:id,name')
                ->get()
                ->groupBy(fn (Task $task) => $task->portfolio?->name ?? 'No portfolio')
                ->map(fn ($tasks, string $label) => ['label' => $label, 'count' => $tasks->count()])
                ->sortByDesc('count')
                ->values()
                ->all(),
            'byProject' => (clone $taskQuery)
                ->with('project:id,name')
                ->get()
                ->groupBy(fn (Task $task) => $task->project?->name ?? 'No project')
                ->map(fn ($tasks, string $label) => ['label' => $label, 'count' => $tasks->count()])
                ->sortByDesc('count')
                ->values()
                ->all(),
        ];
    }

    private function goalMetrics(array $workspaceIds): array
    {
        return Goal::query()
            ->with(['owner:id,name'])
            ->withCount(['projects', 'keyResults'])
            ->when(
                $workspaceIds !== [],
                fn (Builder $query) => $query->whereIn('workspace_id', $workspaceIds),
                fn (Builder $query) => $query->whereRaw('1 = 0'),
            )
            ->where('status', '!=', 'archived')
            ->orderByRaw('target_date is null')
            ->orderBy('target_date')
            ->get()
            ->map(fn (Goal $goal) => [
                'id' => $goal->id,
                'title' => $goal->title,
                'status' => $goal->status,
                'target_date' => $goal->target_date?->toDateString(),
                'progress_percentage' => $goal->progress_percentage,
                'owner' => $goal->owner?->only(['id', 'name']),
                'projects_count' => $goal->projects_count,
                'key_results_count' => $goal->key_results_count,
            ])
            ->all();
    }

    private function workloadMetrics(Builder $taskQuery, array $workspaceIds): array
    {
        $tasks = (clone $taskQuery)->with('assignee:id,name')->get();
        $users = User::query()
            ->select(['id', 'name'])
            ->whereHas('workspaces', fn (Builder $query) => $query->whereIn('workspaces.id', $workspaceIds))
            ->orderBy('name')
            ->get();

        return $users->map(function (User $user) use ($tasks): array {
            $assigned = $tasks->where('assignee_id', $user->id);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'open_tasks' => $assigned->filter(fn (Task $task) => ! in_array($task->status, ['completed', 'archived'], true))->count(),
                'overdue_tasks' => $assigned->filter(fn (Task $task) => $task->status !== 'completed' && $task->due_date && $task->due_date->toDateString() < now()->toDateString())->count(),
                'due_this_week' => $assigned->filter(fn (Task $task) => $task->status !== 'completed' && $task->due_date && $task->due_date->betweenIncluded(now(), now()->addDays(7)))->count(),
                'completed_this_week' => $assigned->filter(fn (Task $task) => $task->status === 'completed' && ($task->completed_at?->betweenIncluded(now()->startOfWeek(), now()->endOfWeek()) ?? false))->count(),
            ];
        })->values()->all();
    }

    private function launchReadiness(array $workspaceIds): array
    {
        return Portfolio::query()
            ->when($workspaceIds !== [], fn (Builder $query) => $query->whereIn('workspace_id', $workspaceIds))
            ->whereIn('name', ['SayaraForce', 'ChurchForce'])
            ->with('area:id,name')
            ->orderByRaw("case name when 'SayaraForce' then 1 when 'ChurchForce' then 2 else 3 end")
            ->get()
            ->map(function (Portfolio $portfolio): array {
                $tasks = $portfolio->tasks();
                $total = (clone $tasks)->count();
                $completed = (clone $tasks)->where('status', 'completed')->count();
                $open = (clone $tasks)->active()->count();

                return [
                    ...$this->portfolioMetricResource($portfolio),
                    'area' => $portfolio->area?->only(['id', 'name']),
                    'total_launch_tasks' => $total,
                    'completed_tasks' => $completed,
                    'open_tasks' => $open,
                    'overdue_tasks' => (clone $tasks)->active()->overdue()->count(),
                    'completion' => $this->progress($completed, $total),
                    'urgent_open_tasks' => (clone $tasks)->active()->where('priority', 'urgent')->count(),
                    'high_open_tasks' => (clone $tasks)->active()->where('priority', 'high')->count(),
                    'next_open_tasks' => (clone $tasks)
                        ->active()
                        ->with(['project:id,name', 'area:id,name'])
                        ->orderByRaw("case priority when 'urgent' then 1 when 'high' then 2 when 'medium' then 3 when 'low' then 4 else 5 end")
                        ->orderByRaw('due_date is null')
                        ->orderBy('due_date')
                        ->limit(10)
                        ->get()
                        ->map(fn (Task $task) => $this->taskResource($task))
                        ->all(),
                ];
            })
            ->all();
    }

    private function trends(Builder $taskQuery): array
    {
        $start = now()->startOfWeek();
        $end = now()->endOfWeek();

        return [
            'created_this_week' => (clone $taskQuery)->whereBetween('created_at', [$start, $end])->count(),
            'completed_this_week' => (clone $taskQuery)->where('status', 'completed')->whereBetween('completed_at', [$start, $end])->count(),
            'recently_completed_or_updated' => (clone $taskQuery)->where('status', 'completed')->whereNull('completed_at')->whereBetween('updated_at', [$start, $end])->count(),
            'overdue_this_week' => (clone $taskQuery)->active()->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])->whereDate('due_date', '<', now()->toDateString())->count(),
        ];
    }

    private function options(array $workspaceIds): array
    {
        return [
            'areas' => Area::query()->select(['id', 'name'])->orderBy('position')->orderBy('name')->get(),
            'portfolios' => $this->workspacePortfolios($workspaceIds)->select(['id', 'area_id', 'name'])->orderBy('name')->get(),
            'statuses' => Task::STATUSES,
            'priorities' => Task::PRIORITIES,
            'dueBuckets' => [
                ['value' => 'overdue', 'label' => 'Overdue'],
                ['value' => 'today', 'label' => 'Today'],
                ['value' => 'this_week', 'label' => 'This week'],
                ['value' => 'no_due_date', 'label' => 'No due date'],
            ],
        ];
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

    private function applyTaskFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['area_id'] !== '', fn (Builder $query) => $query->where('area_id', (int) $filters['area_id']))
            ->when($filters['portfolio_id'] !== '', fn (Builder $query) => $query->where('portfolio_id', (int) $filters['portfolio_id']))
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['priority'] !== '', fn (Builder $query) => $query->where('priority', $filters['priority']))
            ->when($filters['due_bucket'] !== '', fn (Builder $query) => $this->applyDueBucket($query, $filters['due_bucket']));
    }

    private function applyProjectFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['area_id'] !== '', fn (Builder $query) => $query->where('area_id', (int) $filters['area_id']))
            ->when($filters['portfolio_id'] !== '', fn (Builder $query) => $query->where('portfolio_id', (int) $filters['portfolio_id']))
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('status', $filters['status']));
    }

    private function applyPortfolioFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['area_id'] !== '', fn (Builder $query) => $query->where('area_id', (int) $filters['area_id']))
            ->when($filters['portfolio_id'] !== '', fn (Builder $query) => $query->whereKey((int) $filters['portfolio_id']));
    }

    private function applyDueBucket(Builder $query, string $bucket): Builder
    {
        return match ($bucket) {
            'overdue' => $query->active()->overdue(),
            'today' => $query->active()->dueToday(),
            'this_week' => $query->active()->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()]),
            'no_due_date' => $query->active()->whereNull('due_date'),
            default => $query,
        };
    }

    private function groupCount(Builder $query, string $column): array
    {
        return (clone $query)
            ->selectRaw("{$column} as label, count(*) as count")
            ->groupBy($column)
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => ['label' => $row->label ?: 'None', 'count' => (int) $row->count])
            ->all();
    }

    private function portfolioMetricResource(Portfolio $portfolio): array
    {
        $totalTasks = (int) ($portfolio->total_tasks_count ?? $portfolio->tasks()->count());
        $completedTasks = (int) ($portfolio->completed_tasks_count ?? $portfolio->tasks()->where('status', 'completed')->count());

        return [
            'id' => $portfolio->id,
            'name' => $portfolio->name,
            'status' => $portfolio->status,
            'area' => $portfolio->area?->only(['id', 'name']),
            'total_projects' => (int) ($portfolio->total_projects_count ?? $portfolio->projects()->count()),
            'total_tasks' => $totalTasks,
            'open_tasks' => (int) ($portfolio->open_tasks_count ?? $portfolio->tasks()->active()->count()),
            'completed_tasks' => $completedTasks,
            'overdue_tasks' => (int) ($portfolio->overdue_tasks_count ?? $portfolio->tasks()->active()->overdue()->count()),
            'progress' => $this->progress($completedTasks, $totalTasks),
        ];
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
            'area' => $task->area?->only(['id', 'name']),
        ];
    }

    private function progress(int $completed, int $total): ?int
    {
        return $total > 0 ? (int) round(($completed / $total) * 100) : null;
    }

    private function calculatedProjectHealth(Project $project): string
    {
        if ($project->status === 'completed') {
            return 'completed';
        }

        if ((int) $project->overdue_tasks_count > 0) {
            return 'off_track';
        }

        if ($project->due_date && $project->due_date->toDateString() <= now()->addDays(7)->toDateString() && (int) $project->open_tasks_count > 0) {
            return 'at_risk';
        }

        return $project->status === 'archived' ? 'archived' : 'on_track';
    }
}
