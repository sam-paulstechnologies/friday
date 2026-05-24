<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Portfolio;
use App\Models\Task;
use Inertia\Inertia;
use Inertia\Response;

class PortfolioController extends Controller
{
    public function index(): Response
    {
        $areas = Area::query()
            ->with(['portfolios' => fn ($query) => $query
                ->withCount($this->portfolioCountQueries())
                ->orderBy('position')])
            ->orderBy('position')
            ->get()
            ->map(fn (Area $area) => [
                'id' => $area->id,
                'name' => $area->name,
                'color' => $area->color,
                'portfolios' => $area->portfolios->map(fn (Portfolio $portfolio) => $this->portfolioResource($portfolio)),
            ]);

        return Inertia::render('Portfolios/Index', [
            'areas' => $areas,
        ]);
    }

    public function show(Portfolio $portfolio): Response
    {
        $portfolio->load([
            'area:id,name,color',
            'projects' => fn ($query) => $query->with(['owner:id,name'])->active()->orderBy('sort_order')->latest(),
        ])->loadCount($this->portfolioCountQueries());

        $tasks = Task::query()
            ->with(['workspace:id,name', 'project:id,name', 'area:id,name', 'assignee:id,name'])
            ->where('portfolio_id', $portfolio->id)
            ->active()
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->get();

        return Inertia::render('Portfolios/Show', [
            'portfolio' => [
                ...$this->portfolioResource($portfolio),
                'area' => $portfolio->area?->only(['id', 'name', 'color']),
            ],
            'projects' => $portfolio->projects->map(fn ($project) => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
                'health' => $project->health,
                'due_date' => $project->due_date?->toDateString(),
                'owner' => $project->owner?->only(['id', 'name']),
            ]),
            'tasks' => $this->groupTasks($tasks),
        ]);
    }

    private function portfolioResource(Portfolio $portfolio): array
    {
        $openTasks = (int) ($portfolio->open_tasks_count ?? 0);
        $completedTasks = (int) ($portfolio->completed_tasks_count ?? 0);
        $totalProgressTasks = $openTasks + $completedTasks;

        return [
            'id' => $portfolio->id,
            'name' => $portfolio->name,
            'slug' => $portfolio->slug,
            'description' => $portfolio->description,
            'color' => $portfolio->color,
            'icon' => $portfolio->icon,
            'status' => $portfolio->status,
            'project_count' => $portfolio->total_projects_count ?? $portfolio->projects_count ?? 0,
            'task_count' => $portfolio->total_tasks_count ?? $portfolio->tasks_count ?? 0,
            'total_projects_count' => $portfolio->total_projects_count ?? $portfolio->projects_count ?? 0,
            'total_tasks_count' => $portfolio->total_tasks_count ?? $portfolio->tasks_count ?? 0,
            'open_tasks_count' => $openTasks,
            'completed_tasks_count' => $completedTasks,
            'overdue_tasks_count' => $portfolio->overdue_tasks_count ?? 0,
            'progress_percentage' => $totalProgressTasks > 0
                ? (int) round(($completedTasks / $totalProgressTasks) * 100)
                : null,
        ];
    }

    private function portfolioCountQueries(): array
    {
        return [
            'projects as total_projects_count',
            'tasks as total_tasks_count',
            'tasks as open_tasks_count' => fn ($query) => $query->active(),
            'tasks as completed_tasks_count' => fn ($query) => $query->where('status', 'completed'),
            'tasks as overdue_tasks_count' => fn ($query) => $query->active()->overdue(),
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
            'area' => $task->area?->only(['id', 'name']),
            'assignee' => $task->assignee?->only(['id', 'name']),
        ])->all();
    }
}
