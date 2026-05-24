<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'visibility']);

        $projects = Project::query()
            ->with(['workspace:id,name', 'team:id,name', 'owner:id,name', 'area:id,name,color', 'portfolio:id,name'])
            ->withCount([
                'tasks as open_tasks_count' => fn ($query) => $query->active(),
                'tasks as completed_tasks_count' => fn ($query) => $query->where('status', 'completed'),
            ])
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['visibility'] ?? null, fn ($query, string $visibility) => $query->where('visibility', $visibility))
            ->latest()
            ->get()
            ->map(fn (Project $project) => $this->projectResource($project));

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
                'visibility' => $filters['visibility'] ?? '',
            ],
            'statuses' => Project::STATUSES,
            'visibilities' => Project::VISIBILITIES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Projects/Create', $this->formOptions());
    }

    public function store(Request $request)
    {
        $data = $this->validatedProjectData($request);
        $data['owner_id'] = $request->user()->id;
        $data['slug'] = $this->uniqueSlug($data['name'], (int) $data['workspace_id']);

        $project = Project::create($data);

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'Project created.');
    }

    public function show(Project $project): Response
    {
        $project->load([
            'workspace:id,name',
            'team:id,name',
            'owner:id,name',
            'area:id,name,color',
            'portfolio:id,name',
            'tasks' => fn ($query) => $query
                ->with(['assignee:id,name', 'area:id,name', 'portfolio:id,name'])
                ->whereNull('parent_task_id')
                ->orderBy('position')
                ->latest(),
        ]);

        return Inertia::render('Projects/Show', [
            'project' => $this->projectResource($project),
            'tasks' => $project->tasks->map(fn (Task $task) => $this->taskResource($task)),
        ]);
    }

    public function edit(Project $project): Response
    {
        $project->load(['workspace:id,name', 'team:id,name', 'owner:id,name', 'area:id,name,color', 'portfolio:id,name']);

        return Inertia::render('Projects/Edit', [
            'project' => $this->projectResource($project),
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, Project $project)
    {
        $data = $this->validatedProjectData($request);

        if ($project->name !== $data['name'] || $project->workspace_id !== (int) $data['workspace_id']) {
            $data['slug'] = $this->uniqueSlug($data['name'], (int) $data['workspace_id'], $project->id);
        }

        $project->update($data);

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'Project updated.');
    }

    public function archive(Project $project)
    {
        $project->update(['status' => 'archived']);

        return redirect()
            ->route('projects.index')
            ->with('success', 'Project archived.');
    }

    private function validatedProjectData(Request $request): array
    {
        return $request->validate([
            'workspace_id' => ['required', 'integer', Rule::exists('workspaces', 'id')],
            'area_id' => ['nullable', 'integer', Rule::exists('areas', 'id')],
            'portfolio_id' => ['nullable', 'integer', Rule::exists('portfolios', 'id')],
            'team_id' => ['nullable', 'integer', Rule::exists('teams', 'id')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in(Project::STATUSES)],
            'visibility' => ['required', Rule::in(Project::VISIBILITIES)],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'color' => ['nullable', 'string', 'max:32'],
            'project_type' => ['nullable', 'string', 'max:255'],
            'health' => ['nullable', Rule::in(['on_track', 'at_risk', 'off_track', 'paused'])],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function formOptions(): array
    {
        return [
            'workspaces' => Workspace::query()
                ->select(['id', 'name'])
                ->orderBy('name')
                ->get(),
            'areas' => Area::query()->select(['id', 'name'])->orderBy('position')->get(),
            'portfolios' => Portfolio::query()->select(['id', 'area_id', 'name'])->orderBy('name')->get(),
            'teams' => Team::query()
                ->select(['id', 'workspace_id', 'name'])
                ->orderBy('name')
                ->get(),
            'statuses' => Project::STATUSES,
            'visibilities' => Project::VISIBILITIES,
            'healthOptions' => ['on_track', 'at_risk', 'off_track', 'paused'],
        ];
    }

    private function projectResource(Project $project): array
    {
        return [
            'id' => $project->id,
            'area_id' => $project->area_id,
            'portfolio_id' => $project->portfolio_id,
            'workspace_id' => $project->workspace_id,
            'team_id' => $project->team_id,
            'owner_id' => $project->owner_id,
            'name' => $project->name,
            'slug' => $project->slug,
            'description' => $project->description,
            'status' => $project->status,
            'visibility' => $project->visibility,
            'start_date' => $project->start_date?->toDateString(),
            'due_date' => $project->due_date?->toDateString(),
            'color' => $project->color,
            'project_type' => $project->project_type,
            'health' => $project->health,
            'sort_order' => $project->sort_order,
            'area' => $project->area ? [
                'id' => $project->area->id,
                'name' => $project->area->name,
                'color' => $project->area->color,
            ] : null,
            'portfolio' => $project->portfolio ? [
                'id' => $project->portfolio->id,
                'name' => $project->portfolio->name,
            ] : null,
            'workspace' => $project->workspace ? [
                'id' => $project->workspace->id,
                'name' => $project->workspace->name,
            ] : null,
            'team' => $project->team ? [
                'id' => $project->team->id,
                'name' => $project->team->name,
            ] : null,
            'owner' => $project->owner ? [
                'id' => $project->owner->id,
                'name' => $project->owner->name,
            ] : null,
            'open_tasks_count' => $project->open_tasks_count ?? null,
            'completed_tasks_count' => $project->completed_tasks_count ?? null,
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
            'area' => $task->area ? [
                'id' => $task->area->id,
                'name' => $task->area->name,
            ] : null,
            'portfolio' => $task->portfolio ? [
                'id' => $task->portfolio->id,
                'name' => $task->portfolio->name,
            ] : null,
            'assignee' => $task->assignee ? [
                'id' => $task->assignee->id,
                'name' => $task->assignee->name,
            ] : null,
        ];
    }

    private function uniqueSlug(string $name, int $workspaceId, ?int $ignoreProjectId = null): string
    {
        $base = Str::slug($name) ?: 'project';
        $slug = $base;
        $counter = 2;

        while (
            Project::query()
                ->where('workspace_id', $workspaceId)
                ->where('slug', $slug)
                ->when($ignoreProjectId, fn ($query) => $query->whereKeyNot($ignoreProjectId))
                ->exists()
        ) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
