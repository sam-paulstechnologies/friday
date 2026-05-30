<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use App\Models\GoalKeyResult;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GoalController extends Controller
{
    public function index(Request $request): Response
    {
        $workspaceIds = $request->user()->accessibleWorkspaceIds();

        $goals = Goal::query()
            ->with(['workspace:id,name', 'owner:id,name'])
            ->withCount(['projects', 'keyResults'])
            ->when(
                $workspaceIds !== [],
                fn ($query) => $query->whereIn('workspace_id', $workspaceIds),
                fn ($query) => $query->whereRaw('1 = 0'),
            )
            ->when(! $request->filled('status'), fn ($query) => $query->where('status', '!=', 'archived'))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderByRaw('target_date is null')
            ->orderBy('target_date')
            ->latest()
            ->get()
            ->map(fn (Goal $goal) => $this->goalResource($goal));

        return Inertia::render('Goals/Index', [
            'goals' => $goals,
            'filters' => ['status' => $request->string('status')->toString()],
            'statuses' => Goal::STATUSES,
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Goal::class);

        return Inertia::render('Goals/Create', $this->formOptions($request));
    }

    public function store(Request $request)
    {
        Gate::authorize('create', Goal::class);

        $goal = Goal::create($this->validatedGoalData($request));
        $this->syncProjects($goal, $request);
        $this->recalculateProgress($goal);
        $this->logActivity($goal, $request->user()->id, 'goal_created', 'Goal was created.');

        return redirect()->route('goals.show', $goal)->with('success', 'Goal created.');
    }

    public function show(Request $request, Goal $goal): Response
    {
        Gate::authorize('view', $goal);

        $goal->load([
            'workspace:id,name',
            'owner:id,name',
            'projects' => fn ($query) => $query->withCount([
                'tasks as open_tasks_count' => fn ($query) => $query->active(),
                'tasks as completed_tasks_count' => fn ($query) => $query->where('status', 'completed'),
                'tasks as overdue_tasks_count' => fn ($query) => $query->active()->overdue(),
            ])->orderBy('name'),
            'keyResults' => fn ($query) => $query->orderBy('id'),
            'activities' => fn ($query) => $query->with('user:id,name')->latest()->limit(20),
        ]);

        return Inertia::render('Goals/Show', [
            'goal' => $this->goalResource($goal),
            'projects' => $goal->projects->map(fn (Project $project) => $this->projectResource($project))->values(),
            'keyResults' => $goal->keyResults->map(fn (GoalKeyResult $keyResult) => $this->keyResultResource($keyResult))->values(),
            'activities' => $goal->activities->map(fn ($activity) => [
                'id' => $activity->id,
                'action' => $activity->action,
                'description' => $activity->description,
                'created_at' => $activity->created_at?->toDateTimeString(),
                'user' => $activity->user?->only(['id', 'name']),
            ])->values(),
        ]);
    }

    public function edit(Request $request, Goal $goal): Response
    {
        Gate::authorize('update', $goal);

        $goal->load('projects:id');

        return Inertia::render('Goals/Edit', [
            'goal' => [
                ...$this->goalResource($goal),
                'project_ids' => $goal->projects->pluck('id')->values(),
            ],
            ...$this->formOptions($request),
        ]);
    }

    public function update(Request $request, Goal $goal)
    {
        Gate::authorize('update', $goal);

        $goal->update($this->validatedGoalData($request));
        $this->syncProjects($goal, $request);
        $this->recalculateProgress($goal);
        $this->logActivity($goal, $request->user()->id, 'goal_updated', 'Goal was updated.');

        return redirect()->route('goals.show', $goal)->with('success', 'Goal updated.');
    }

    public function archive(Goal $goal)
    {
        Gate::authorize('delete', $goal);

        $goal->update(['status' => 'archived']);
        $this->logActivity($goal, request()->user()->id, 'goal_archived', 'Goal was archived.');

        return redirect()->route('goals.index')->with('success', 'Goal archived.');
    }

    public function restore(Goal $goal)
    {
        Gate::authorize('update', $goal);

        $goal->update(['status' => 'on_track']);
        $this->logActivity($goal, request()->user()->id, 'goal_restored', 'Goal was restored.');

        return redirect()->route('goals.show', $goal)->with('success', 'Goal restored.');
    }

    public function storeKeyResult(Request $request, Goal $goal)
    {
        Gate::authorize('update', $goal);

        $keyResult = $goal->keyResults()->create($this->validatedKeyResultData($request));
        $this->recalculateKeyResult($keyResult);
        $this->recalculateProgress($goal);
        $this->logActivity($goal, $request->user()->id, 'key_result_created', 'Key result was added.');

        return back()->with('success', 'Key result added.');
    }

    public function updateKeyResult(Request $request, Goal $goal, GoalKeyResult $keyResult)
    {
        Gate::authorize('update', $goal);
        abort_unless($keyResult->goal_id === $goal->id, 404);

        $keyResult->update($this->validatedKeyResultData($request));
        $this->recalculateKeyResult($keyResult);
        $this->recalculateProgress($goal);
        $this->logActivity($goal, $request->user()->id, 'key_result_updated', 'Key result was updated.');

        return back()->with('success', 'Key result updated.');
    }

    private function validatedGoalData(Request $request): array
    {
        $workspaceIds = $request->user()->accessibleWorkspaceIds();
        $workspaceUserIds = $request->user()->workspaceUsersQuery()->pluck('id')->all();

        return $request->validate([
            'workspace_id' => ['required', 'integer', Rule::exists('workspaces', 'id')->where(fn ($query) => $query->whereIn('id', $workspaceIds))],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('id', $workspaceUserIds))],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in(Goal::STATUSES)],
            'target_date' => ['nullable', 'date'],
            'progress_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);
    }

    private function validatedKeyResultData(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'target_value' => ['required', 'numeric', 'min:0.01'],
            'current_value' => ['required', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'status' => ['required', Rule::in(Goal::STATUSES)],
        ]);
    }

    private function formOptions(Request $request): array
    {
        $workspaceIds = $request->user()->accessibleWorkspaceIds();

        return [
            'workspaces' => Workspace::query()->select(['id', 'name'])->whereIn('id', $workspaceIds)->orderBy('name')->get(),
            'users' => User::query()
                ->select(['id', 'name'])
                ->whereHas('workspaces', fn ($query) => $query->whereIn('workspaces.id', $workspaceIds))
                ->orderBy('name')
                ->get(),
            'projects' => Project::query()
                ->select(['id', 'workspace_id', 'name'])
                ->whereIn('workspace_id', $workspaceIds)
                ->where('status', '!=', 'archived')
                ->orderBy('name')
                ->get(),
            'statuses' => Goal::STATUSES,
        ];
    }

    private function syncProjects(Goal $goal, Request $request): void
    {
        $projectIds = collect($request->input('project_ids', []))->map(fn ($id) => (int) $id)->filter()->values();
        $allowedProjectIds = Project::query()
            ->where('workspace_id', $goal->workspace_id)
            ->whereIn('id', $projectIds)
            ->pluck('id')
            ->all();

        $goal->projects()->sync($allowedProjectIds);
    }

    private function recalculateKeyResult(GoalKeyResult $keyResult): void
    {
        $progress = min(100, (int) round(((float) $keyResult->current_value / max(0.01, (float) $keyResult->target_value)) * 100));
        $status = $progress >= 100 ? 'completed' : $keyResult->status;

        $keyResult->forceFill([
            'progress_percentage' => $progress,
            'status' => $status,
        ])->save();
    }

    private function recalculateProgress(Goal $goal): void
    {
        $goal->loadMissing('keyResults');
        $progress = $goal->keyResults->isNotEmpty()
            ? (int) round($goal->keyResults->avg('progress_percentage'))
            : (int) $goal->progress_percentage;

        $goal->forceFill(['progress_percentage' => min(100, max(0, $progress))])->save();
    }

    private function goalResource(Goal $goal): array
    {
        return [
            'id' => $goal->id,
            'workspace_id' => $goal->workspace_id,
            'owner_id' => $goal->owner_id,
            'title' => $goal->title,
            'description' => $goal->description,
            'status' => $goal->status,
            'target_date' => $goal->target_date?->toDateString(),
            'progress_percentage' => $goal->progress_percentage,
            'workspace' => $goal->workspace?->only(['id', 'name']),
            'owner' => $goal->owner?->only(['id', 'name']),
            'projects_count' => $goal->projects_count ?? null,
            'key_results_count' => $goal->key_results_count ?? null,
        ];
    }

    private function projectResource(Project $project): array
    {
        $total = (int) (($project->open_tasks_count ?? 0) + ($project->completed_tasks_count ?? 0));

        return [
            'id' => $project->id,
            'name' => $project->name,
            'status' => $project->status,
            'health' => $project->health,
            'due_date' => $project->due_date?->toDateString(),
            'open_tasks_count' => (int) ($project->open_tasks_count ?? 0),
            'completed_tasks_count' => (int) ($project->completed_tasks_count ?? 0),
            'overdue_tasks_count' => (int) ($project->overdue_tasks_count ?? 0),
            'progress_percentage' => $total > 0 ? (int) round(((int) $project->completed_tasks_count / $total) * 100) : null,
        ];
    }

    private function keyResultResource(GoalKeyResult $keyResult): array
    {
        return [
            'id' => $keyResult->id,
            'title' => $keyResult->title,
            'target_value' => (float) $keyResult->target_value,
            'current_value' => (float) $keyResult->current_value,
            'unit' => $keyResult->unit,
            'status' => $keyResult->status,
            'progress_percentage' => $keyResult->progress_percentage,
        ];
    }

    private function logActivity(Goal $goal, ?int $userId, string $action, ?string $description = null): void
    {
        $goal->activities()->create([
            'user_id' => $userId,
            'action' => $action,
            'description' => $description,
        ]);
    }
}
