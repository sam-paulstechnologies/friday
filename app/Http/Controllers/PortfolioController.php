<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PortfolioController extends Controller
{
    public function index(Request $request): Response
    {
        $workspaceIds = $request->user()->accessibleWorkspaceIds();

        $areas = Area::query()
            ->with(['portfolios' => fn ($query) => $query
                ->whereIn('workspace_id', $workspaceIds)
                ->where('status', '!=', 'archived')
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

    public function create(Request $request): Response
    {
        Gate::authorize('create', Portfolio::class);

        return Inertia::render('Portfolios/Create', $this->formOptions($request));
    }

    public function store(Request $request)
    {
        Gate::authorize('create', Portfolio::class);

        $data = $this->validatedPortfolioData($request);
        $data['slug'] = $this->uniqueSlug($data['name'], (int) $data['workspace_id']);
        $portfolio = Portfolio::create($data);

        return redirect()->route('portfolios.show', $portfolio)->with('success', 'Portfolio created.');
    }

    public function show(Request $request, Portfolio $portfolio): Response
    {
        Gate::authorize('view', $portfolio);
        $workspaceIds = $request->user()->accessibleWorkspaceIds();

        $portfolio->load([
            'area:id,name,color',
            'owner:id,name',
            'projects' => fn ($query) => $query->whereIn('workspace_id', $workspaceIds)->with(['owner:id,name'])->active()->orderBy('sort_order')->latest(),
        ])->loadCount($this->portfolioCountQueries());

        $tasks = Task::query()
            ->with(['workspace:id,name', 'project:id,name', 'area:id,name', 'assignee:id,name'])
            ->where('portfolio_id', $portfolio->id)
            ->whereIn('workspace_id', $workspaceIds)
            ->active()
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->get();

        return Inertia::render('Portfolios/Show', [
            'portfolio' => [
                ...$this->portfolioResource($portfolio),
                'area' => $portfolio->area?->only(['id', 'name', 'color']),
                'owner' => $portfolio->owner?->only(['id', 'name']),
            ],
            'projects' => $portfolio->projects->map(fn (Project $project) => $this->projectResource($project)),
            'tasks' => $this->groupTasks($tasks),
            'availableProjects' => Project::query()
                ->select(['id', 'name'])
                ->where('workspace_id', $portfolio->workspace_id)
                ->where(function ($query) use ($portfolio): void {
                    $query->whereNull('portfolio_id')->orWhere('portfolio_id', '!=', $portfolio->id);
                })
                ->where('status', '!=', 'archived')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function edit(Request $request, Portfolio $portfolio): Response
    {
        Gate::authorize('update', $portfolio);

        return Inertia::render('Portfolios/Edit', [
            'portfolio' => $this->portfolioResource($portfolio),
            ...$this->formOptions($request),
        ]);
    }

    public function update(Request $request, Portfolio $portfolio)
    {
        Gate::authorize('update', $portfolio);

        $data = $this->validatedPortfolioData($request);
        if ($portfolio->name !== $data['name'] || $portfolio->workspace_id !== (int) $data['workspace_id']) {
            $data['slug'] = $this->uniqueSlug($data['name'], (int) $data['workspace_id'], $portfolio->id);
        }

        $portfolio->update($data);

        return redirect()->route('portfolios.show', $portfolio)->with('success', 'Portfolio updated.');
    }

    public function archive(Portfolio $portfolio)
    {
        Gate::authorize('delete', $portfolio);

        $portfolio->update(['status' => 'archived']);

        return redirect()->route('portfolios.index')->with('success', 'Portfolio archived.');
    }

    public function restore(Portfolio $portfolio)
    {
        Gate::authorize('update', $portfolio);

        $portfolio->update(['status' => 'active']);

        return redirect()->route('portfolios.show', $portfolio)->with('success', 'Portfolio restored.');
    }

    public function addProject(Request $request, Portfolio $portfolio)
    {
        Gate::authorize('update', $portfolio);

        $data = $request->validate([
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->where(fn ($query) => $query->where('workspace_id', $portfolio->workspace_id))],
        ]);

        Project::whereKey($data['project_id'])->update([
            'portfolio_id' => $portfolio->id,
            'area_id' => $portfolio->area_id,
        ]);

        return back()->with('success', 'Project added to portfolio.');
    }

    public function removeProject(Portfolio $portfolio, Project $project)
    {
        Gate::authorize('update', $portfolio);
        abort_unless($project->portfolio_id === $portfolio->id && $project->workspace_id === $portfolio->workspace_id, 404);

        $project->update(['portfolio_id' => null]);

        return back()->with('success', 'Project removed from portfolio.');
    }

    private function portfolioResource(Portfolio $portfolio): array
    {
        $openTasks = (int) ($portfolio->open_tasks_count ?? 0);
        $completedTasks = (int) ($portfolio->completed_tasks_count ?? 0);
        $totalProgressTasks = $openTasks + $completedTasks;

        return [
            'id' => $portfolio->id,
            'workspace_id' => $portfolio->workspace_id,
            'area_id' => $portfolio->area_id,
            'owner_id' => $portfolio->owner_id,
            'name' => $portfolio->name,
            'slug' => $portfolio->slug,
            'description' => $portfolio->description,
            'color' => $portfolio->color,
            'icon' => $portfolio->icon,
            'status' => $portfolio->status,
            'position' => $portfolio->position,
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

    private function projectResource(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'status' => $project->status,
            'health' => $project->health,
            'due_date' => $project->due_date?->toDateString(),
            'owner' => $project->owner?->only(['id', 'name']),
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

    private function validatedPortfolioData(Request $request): array
    {
        $workspaceIds = $request->user()->accessibleWorkspaceIds();
        $workspaceUserIds = $request->user()->workspaceUsersQuery()->pluck('id')->all();

        return $request->validate([
            'workspace_id' => ['required', 'integer', Rule::exists('workspaces', 'id')->where(fn ($query) => $query->whereIn('id', $workspaceIds))],
            'area_id' => ['nullable', 'integer', Rule::exists('areas', 'id')],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('id', $workspaceUserIds))],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'max:32'],
            'icon' => ['nullable', 'string', 'max:50'],
            'status' => ['required', Rule::in(['active', 'on_hold', 'completed', 'archived'])],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function formOptions(Request $request): array
    {
        $workspaceIds = $request->user()->accessibleWorkspaceIds();

        return [
            'workspaces' => Workspace::query()->select(['id', 'name'])->whereIn('id', $workspaceIds)->orderBy('name')->get(),
            'areas' => Area::query()->select(['id', 'name'])->orderBy('position')->orderBy('name')->get(),
            'users' => User::query()
                ->select(['id', 'name'])
                ->whereHas('workspaces', fn ($query) => $query->whereIn('workspaces.id', $workspaceIds))
                ->orderBy('name')
                ->get(),
            'statuses' => ['active', 'on_hold', 'completed', 'archived'],
        ];
    }

    private function uniqueSlug(string $name, int $workspaceId, ?int $ignorePortfolioId = null): string
    {
        $base = Str::slug($name) ?: 'portfolio';
        $slug = $base;
        $counter = 2;

        while (
            Portfolio::query()
                ->where('workspace_id', $workspaceId)
                ->where('slug', $slug)
                ->when($ignorePortfolioId, fn ($query) => $query->whereKeyNot($ignorePortfolioId))
                ->exists()
        ) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
