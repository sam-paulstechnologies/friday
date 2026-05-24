<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Task;
use Inertia\Inertia;
use Inertia\Response;

class AreaController extends Controller
{
    public function index(): Response
    {
        $areas = Area::query()
            ->withCount(['portfolios', 'projects'])
            ->orderBy('position')
            ->get()
            ->map(fn (Area $area) => [
                ...$this->areaResource($area),
                'portfolio_count' => $area->portfolios_count,
                'project_count' => $area->projects_count,
                'open_task_count' => $area->tasks()->active()->count(),
                'overdue_task_count' => $area->tasks()->active()->overdue()->count(),
                'due_today_count' => $area->tasks()->active()->dueToday()->count(),
            ]);

        return Inertia::render('Areas/Index', [
            'areas' => $areas,
        ]);
    }

    public function show(Area $area): Response
    {
        $area->load([
            'portfolios' => fn ($query) => $query
                ->withCount([
                    'projects as total_projects_count',
                    'tasks as total_tasks_count',
                    'tasks as open_tasks_count' => fn ($query) => $query->active(),
                    'tasks as completed_tasks_count' => fn ($query) => $query->where('status', 'completed'),
                    'tasks as overdue_tasks_count' => fn ($query) => $query->active()->overdue(),
                ])
                ->orderBy('position'),
            'projects' => fn ($query) => $query->with(['portfolio:id,name', 'owner:id,name'])->active()->orderBy('sort_order')->latest(),
        ]);

        $tasks = Task::query()
            ->with(['workspace:id,name', 'project:id,name', 'portfolio:id,name', 'assignee:id,name'])
            ->where('area_id', $area->id)
            ->active()
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->get();

        return Inertia::render('Areas/Show', [
            'area' => $this->areaResource($area),
            'portfolios' => $area->portfolios->map(fn ($portfolio) => [
                'id' => $portfolio->id,
                'name' => $portfolio->name,
                'slug' => $portfolio->slug,
                'status' => $portfolio->status,
                'project_count' => $portfolio->total_projects_count,
                'task_count' => $portfolio->total_tasks_count,
                'total_projects_count' => $portfolio->total_projects_count,
                'total_tasks_count' => $portfolio->total_tasks_count,
                'open_tasks_count' => $portfolio->open_tasks_count,
                'completed_tasks_count' => $portfolio->completed_tasks_count,
                'overdue_tasks_count' => $portfolio->overdue_tasks_count,
            ]),
            'projects' => $area->projects->map(fn ($project) => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
                'health' => $project->health,
                'due_date' => $project->due_date?->toDateString(),
                'portfolio' => $project->portfolio?->only(['id', 'name']),
                'owner' => $project->owner?->only(['id', 'name']),
            ]),
            'tasks' => $this->groupTasks($tasks),
        ]);
    }

    private function areaResource(Area $area): array
    {
        return [
            'id' => $area->id,
            'name' => $area->name,
            'slug' => $area->slug,
            'description' => $area->description,
            'color' => $area->color,
            'icon' => $area->icon,
            'position' => $area->position,
            'is_active' => $area->is_active,
        ];
    }

    private function groupTasks($tasks): array
    {
        $today = now()->toDateString();
        $nextWeek = now()->addDays(7)->toDateString();

        return [
            'overdue' => $this->taskResources($tasks->filter(fn (Task $task) => $task->due_date && $task->due_date->toDateString() < $today)),
            'due_today' => $this->taskResources($tasks->filter(fn (Task $task) => $task->due_date?->toDateString() === $today)),
            'upcoming' => $this->taskResources($tasks->filter(fn (Task $task) => $task->due_date && $task->due_date->toDateString() > $today && $task->due_date->toDateString() <= $nextWeek)),
            'no_due_date' => $this->taskResources($tasks->filter(fn (Task $task) => is_null($task->due_date))),
        ];
    }

    private function taskResources($tasks): array
    {
        return $tasks->values()->map(fn (Task $task) => [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'task_type' => $task->task_type,
            'due_date' => $task->due_date?->toDateString(),
            'project' => $task->project?->only(['id', 'name']),
            'portfolio' => $task->portfolio?->only(['id', 'name']),
            'assignee' => $task->assignee?->only(['id', 'name']),
        ])->all();
    }
}
