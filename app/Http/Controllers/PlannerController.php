<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Calendar\CalendarSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PlannerController extends Controller
{
    public function index(Request $request, CalendarSyncService $calendarSyncService): Response
    {
        $workspaceIds = $request->user()->accessibleWorkspaceIds();
        $filters = $this->filters($request);
        $month = CarbonImmutable::createFromFormat('Y-m-d', ($request->query('month') ?: now()->format('Y-m')).'-01')->startOfMonth();
        $weekStart = CarbonImmutable::parse($request->query('week', now()->startOfWeek()->toDateString()))->startOfWeek();
        $weekEnd = $weekStart->endOfWeek();

        $tasks = $this->applyTaskFilters($this->workspaceTasks($workspaceIds), $filters)
            ->where('status', '!=', 'archived')
            ->with(['assignee:id,name,email', 'project:id,name,due_date,start_date', 'workspace:id,name'])
            ->get();

        $projects = $this->workspaceProjects($workspaceIds)
            ->where('status', '!=', 'archived')
            ->with(['owner:id,name', 'workspace:id,name'])
            ->get();

        return Inertia::render('Planner/Index', [
            'filters' => $filters,
            'options' => $this->options($workspaceIds),
            'month' => [
                'value' => $month->format('Y-m'),
                'label' => $month->format('F Y'),
                'previous' => $month->subMonth()->format('Y-m'),
                'next' => $month->addMonth()->format('Y-m'),
                'today' => now()->format('Y-m'),
            ],
            'week' => [
                'start' => $weekStart->toDateString(),
                'end' => $weekEnd->toDateString(),
                'previous' => $weekStart->subWeek()->toDateString(),
                'next' => $weekStart->addWeek()->toDateString(),
                'label' => $weekStart->format('M j').' - '.$weekEnd->format('M j'),
            ],
            'calendar' => [
                'events' => $this->calendarEvents($tasks, $projects, $month, $calendarSyncService->externalEventsForUser($request->user(), $month->startOfMonth(), $month->endOfMonth())),
                'overdue' => $this->overdueTasks($tasks),
            ],
            'weekPlan' => $this->weekPlan($tasks, $weekStart, $weekEnd),
            'timeline' => $this->timeline($projects, $tasks),
            'workload' => $this->workload($tasks, $workspaceIds),
            'summary' => $this->summary($tasks, $projects, $weekStart, $weekEnd),
        ]);
    }

    public function schedule(Request $request, Task $task)
    {
        Gate::authorize('update', $task);

        $data = $request->validate([
            'due_date' => ['required', 'date'],
        ]);

        $task->update(['due_date' => $data['due_date']]);
        $task->activities()->create([
            'user_id' => $request->user()->id,
            'action' => 'due_date_changed',
            'description' => 'Due date changed from planner.',
            'new_value' => $task->due_date?->toDateString(),
        ]);

        return back()->with('success', 'Task scheduled.');
    }

    private function filters(Request $request): array
    {
        return [
            'project_id' => $request->string('project_id')->toString(),
            'status' => $request->string('status')->toString(),
            'assignee_id' => $request->string('assignee_id')->toString(),
        ];
    }

    private function options(array $workspaceIds): array
    {
        return [
            'projects' => $this->workspaceProjects($workspaceIds)
                ->select(['id', 'name'])
                ->where('status', '!=', 'archived')
                ->orderBy('name')
                ->get(),
            'users' => User::query()
                ->select(['id', 'name'])
                ->when(
                    $workspaceIds !== [],
                    fn (Builder $query) => $query->whereHas('workspaces', fn (Builder $workspaceQuery) => $workspaceQuery->whereIn('workspaces.id', $workspaceIds)),
                    fn (Builder $query) => $query->whereRaw('1 = 0'),
                )
                ->orderBy('name')
                ->get(),
            'statuses' => Task::STATUSES,
        ];
    }

    private function applyTaskFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['project_id'] !== '', fn (Builder $query) => $query->where('project_id', (int) $filters['project_id']))
            ->when($filters['status'] !== '', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when($filters['assignee_id'] !== '', function (Builder $query) use ($filters) {
                return $filters['assignee_id'] === 'unassigned'
                    ? $query->whereNull('assignee_id')
                    : $query->where('assignee_id', (int) $filters['assignee_id']);
            });
    }

    private function calendarEvents(Collection $tasks, Collection $projects, CarbonImmutable $month, array $externalEvents = []): array
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();

        $taskEvents = $tasks
            ->filter(fn (Task $task) => $task->start_date?->betweenIncluded($start, $end) || $task->due_date?->betweenIncluded($start, $end))
            ->flatMap(function (Task $task) use ($start, $end) {
                $events = collect();

                if ($task->start_date?->betweenIncluded($start, $end)) {
                    $events->push($this->event('task_start', 'Task start', $task->title, $task->start_date->toDateString(), route('tasks.show', $task, false), $task));
                }

                if ($task->due_date?->betweenIncluded($start, $end)) {
                    $events->push($this->event('task_due', 'Task due', $task->title, $task->due_date->toDateString(), route('tasks.show', $task, false), $task));
                }

                return $events;
            });

        $projectEvents = $projects
            ->filter(fn (Project $project) => $project->start_date?->betweenIncluded($start, $end) || $project->due_date?->betweenIncluded($start, $end))
            ->flatMap(function (Project $project) use ($start, $end) {
                $events = collect();

                if ($project->start_date?->betweenIncluded($start, $end)) {
                    $events->push($this->event('project_start', 'Project start', $project->name, $project->start_date->toDateString(), route('projects.show', $project, false), null, $project));
                }

                if ($project->due_date?->betweenIncluded($start, $end)) {
                    $events->push($this->event('project_due', 'Project due', $project->name, $project->due_date->toDateString(), route('projects.show', $project, false), null, $project));
                }

                return $events;
            });

        return $taskEvents
            ->merge($projectEvents)
            ->merge($externalEvents)
            ->sortBy(['date', 'type', 'title'])
            ->values()
            ->all();
    }

    private function weekPlan(Collection $tasks, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): array
    {
        $active = $tasks->reject(fn (Task $task) => in_array($task->status, ['completed', 'archived'], true));
        $completed = $tasks->where('status', 'completed');
        $days = collect(range(0, 6))->map(function (int $offset) use ($weekStart, $active, $completed) {
            $date = $weekStart->addDays($offset)->toDateString();

            return [
                'date' => $date,
                'label' => $weekStart->addDays($offset)->format('D M j'),
                'activeTasks' => $this->sortTasks($active->filter(fn (Task $task) => $task->due_date?->toDateString() === $date || $task->start_date?->toDateString() === $date))
                    ->map(fn (Task $task) => $this->taskResource($task))
                    ->values()
                    ->all(),
                'completedTasks' => $this->sortTasks($completed->filter(fn (Task $task) => $task->due_date?->toDateString() === $date || $task->start_date?->toDateString() === $date))
                    ->map(fn (Task $task) => $this->taskResource($task))
                    ->values()
                    ->all(),
            ];
        });

        return [
            'days' => $days->all(),
            'overdue' => $this->overdueTasks($tasks),
            'backlog' => $this->sortTasks($active->filter(fn (Task $task) => is_null($task->due_date) && is_null($task->start_date)))
                ->take(20)
                ->map(fn (Task $task) => $this->taskResource($task))
                ->values()
                ->all(),
        ];
    }

    private function timeline(Collection $projects, Collection $tasks): array
    {
        return $projects
            ->filter(fn (Project $project) => $project->start_date || $project->due_date || $tasks->where('project_id', $project->id)->isNotEmpty())
            ->sortBy(fn (Project $project) => ($project->due_date?->toDateString() ?? '9999-12-31').'-'.$project->name)
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
                'start_date' => $project->start_date?->toDateString(),
                'due_date' => $project->due_date?->toDateString(),
                'overdue' => $project->due_date && $project->due_date->toDateString() < now()->toDateString() && $project->status !== 'completed',
                'href' => route('projects.show', $project, false),
                'tasks' => $this->sortTasks($tasks->where('project_id', $project->id)->filter(fn (Task $task) => $task->start_date || $task->due_date))
                    ->take(8)
                    ->map(fn (Task $task) => $this->taskResource($task))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    private function workload(Collection $tasks, array $workspaceIds): array
    {
        $openTasks = $tasks->reject(fn (Task $task) => in_array($task->status, ['completed', 'archived'], true));
        $users = User::query()
            ->select(['id', 'name'])
            ->when(
                $workspaceIds !== [],
                fn (Builder $query) => $query->whereHas('workspaces', fn (Builder $workspaceQuery) => $workspaceQuery->whereIn('workspaces.id', $workspaceIds)),
                fn (Builder $query) => $query->whereRaw('1 = 0'),
            )
            ->orderBy('name')
            ->get();

        $workloads = $users->map(function (User $user) use ($openTasks, $tasks) {
            $assignedOpen = $openTasks->where('assignee_id', $user->id);

            return [
                'id' => (string) $user->id,
                'name' => $user->name,
                'open_tasks' => $assignedOpen->count(),
                'overdue_tasks' => $assignedOpen->filter(fn (Task $task) => $this->isOverdue($task))->count(),
                'due_this_week' => $assignedOpen->filter(fn (Task $task) => $this->isDueThisWeek($task))->count(),
                'high_priority_tasks' => $assignedOpen->whereIn('priority', ['urgent', 'high'])->count(),
                'recently_completed' => $tasks
                    ->where('assignee_id', $user->id)
                    ->where('status', 'completed')
                    ->filter(fn (Task $task) => $task->completed_at?->gte(now()->subDays(7)) ?? false)
                    ->count(),
            ];
        });

        if ($openTasks->whereNull('assignee_id')->isNotEmpty()) {
            $unassigned = $openTasks->whereNull('assignee_id');
            $workloads->push([
                'id' => 'unassigned',
                'name' => 'Unassigned',
                'open_tasks' => $unassigned->count(),
                'overdue_tasks' => $unassigned->filter(fn (Task $task) => $this->isOverdue($task))->count(),
                'due_this_week' => $unassigned->filter(fn (Task $task) => $this->isDueThisWeek($task))->count(),
                'high_priority_tasks' => $unassigned->whereIn('priority', ['urgent', 'high'])->count(),
                'recently_completed' => 0,
            ]);
        }

        return $workloads->sortByDesc('open_tasks')->values()->all();
    }

    private function summary(Collection $tasks, Collection $projects, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): array
    {
        $openTasks = $tasks->reject(fn (Task $task) => in_array($task->status, ['completed', 'archived'], true));
        $nextProject = $projects
            ->filter(fn (Project $project) => $project->due_date && $project->due_date->toDateString() >= now()->toDateString())
            ->sortBy(fn (Project $project) => $project->due_date->toDateString())
            ->first();

        return [
            'open_tasks' => $openTasks->count(),
            'overdue_tasks' => $openTasks->filter(fn (Task $task) => $this->isOverdue($task))->count(),
            'due_this_week' => $openTasks->filter(fn (Task $task) => $task->due_date && $task->due_date->betweenIncluded($weekStart, $weekEnd))->count(),
            'completed_this_week' => $tasks
                ->where('status', 'completed')
                ->filter(fn (Task $task) => $task->completed_at?->betweenIncluded($weekStart, $weekEnd) ?? false)
                ->count(),
            'next_project_deadline' => $nextProject ? [
                'id' => $nextProject->id,
                'name' => $nextProject->name,
                'due_date' => $nextProject->due_date?->toDateString(),
                'href' => route('projects.show', $nextProject, false),
            ] : null,
        ];
    }

    private function overdueTasks(Collection $tasks): array
    {
        return $this->sortTasks($tasks->filter(fn (Task $task) => $this->isOverdue($task)))
            ->map(fn (Task $task) => $this->taskResource($task))
            ->values()
            ->all();
    }

    private function event(string $type, string $label, string $title, string $date, string $url, ?Task $task = null, ?Project $project = null): array
    {
        return [
            'type' => $type,
            'label' => $label,
            'title' => $title,
            'date' => $date,
            'url' => $url,
            'status' => $task?->status ?? $project?->status,
            'priority' => $task?->priority,
            'completed' => $task?->status === 'completed' || $project?->status === 'completed',
            'overdue' => $date < now()->toDateString() && ($task?->status ?? $project?->status) !== 'completed',
        ];
    }

    private function taskResource(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'start_date' => $task->start_date?->toDateString(),
            'due_date' => $task->due_date?->toDateString(),
            'completed_at' => $task->completed_at?->toDateTimeString(),
            'project' => $task->project?->only(['id', 'name']),
            'assignee' => $task->assignee?->only(['id', 'name']),
            'href' => route('tasks.show', $task, false),
            'overdue' => $this->isOverdue($task),
        ];
    }

    private function sortTasks(Collection $tasks): Collection
    {
        return $tasks->sortBy(fn (Task $task) => $this->priorityRank($task->priority).'-'.($task->due_date?->toDateString() ?? '9999-12-31').'-'.$task->id);
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

    private function isOverdue(Task $task): bool
    {
        return $task->due_date !== null
            && $task->due_date->toDateString() < now()->toDateString()
            && $task->status !== 'completed';
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
