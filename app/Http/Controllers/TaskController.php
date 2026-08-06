<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Label;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\TaskFlowNotification;
use App\Services\Collaboration\TaskCollaborationService;
use App\Services\MiriamReminderService;
use App\Services\Tasks\InvalidTaskTransitionException;
use App\Services\Tasks\RecurringTaskService;
use App\Services\Tasks\TaskTransitionService;
use App\Support\OperationalClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    private const PER_PAGE = 50;

    /**
     * My Tasks.
     *
     * One server-paginated view at a time, selected by `view`. Sections come
     * from the canonical workflow state rather than ad-hoc client filtering,
     * and captures still sitting in the Inbox are excluded - they are not work
     * the operator has agreed to yet.
     */
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'priority', 'project_id', 'workflow_state']);
        $view = $this->resolveView($request->string('view')->toString());
        $today = app(OperationalClock::class)->todayString();
        $workspaceIds = $request->user()->accessibleWorkspaceIds();

        $query = $this->filteredTaskQuery($request, $filters);
        $this->applyView($query, $view, $today);

        if (in_array($view, ['completed', 'archived'], true)) {
            $this->orderCompletedTasks($query);
        } else {
            $this->orderActiveTasks($query);
        }

        $tasks = $query
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Task $task) => $this->taskResource($task));

        return Inertia::render('Tasks/Index', [
            'tasks' => $tasks,
            'view' => $view,
            'views' => $this->viewDefinitions(),
            'viewCounts' => $this->viewCounts($request, $filters, $today),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
                'priority' => $filters['priority'] ?? '',
                'project_id' => $filters['project_id'] ?? '',
                'workflow_state' => $filters['workflow_state'] ?? '',
            ],
            'statuses' => Task::STATUSES,
            'priorities' => Task::PRIORITIES,
            'workflowStates' => $this->workflowOptions(),
            'projects' => $this->accessibleProjects($workspaceIds)->select(['id', 'name'])->orderBy('name')->get(),
        ]);
    }

    /** JSON for the task detail drawer. Same authorization as the full page. */
    public function panel(Task $task): JsonResponse
    {
        Gate::authorize('view', $task);

        $task->load([
            'workspace:id,name',
            'project:id,name',
            'area:id,name',
            'portfolio:id,name',
            'parentTask:id,title',
            'labels:id,name,color',
            'subtasks' => fn ($query) => $query->with('assignee:id,name')->orderBy('position')->oldest(),
            'assignee:id,name',
            'reporter:id,name',
            'comments' => fn ($query) => $query->with('user:id,name')->latest()->limit(20),
            'activities' => fn ($query) => $query->with('user:id,name')->latest()->limit(20),
            'attachments' => fn ($query) => $query->with('user:id,name')->latest(),
        ]);

        return response()->json([
            'task' => $this->taskResource($task),
            'can' => [
                'update' => Gate::allows('update', $task),
                'delete' => Gate::allows('delete', $task),
            ],
            'transitions' => app(TaskTransitionService::class)->availableFor($task),
        ]);
    }

    /** @return array<string, string> */
    private function viewDefinitions(): array
    {
        return [
            'today' => 'Today',
            'this_week' => 'This week',
            'upcoming' => 'Upcoming',
            'overdue' => 'Overdue',
            'waiting' => 'Waiting',
            'delegated' => 'Delegated',
            'later' => 'Later',
            'completed' => 'Completed',
            'all' => 'All active',
            'archived' => 'Archived',
        ];
    }

    private function resolveView(?string $view): string
    {
        return array_key_exists((string) $view, $this->viewDefinitions()) ? (string) $view : 'today';
    }

    private function applyView($query, string $view, string $today): void
    {
        if ($view === 'completed') {
            $query->where('status', 'completed');

            return;
        }

        if ($view === 'archived') {
            $query->where('status', 'archived');

            return;
        }

        // Everything else is active work, and never an untriaged capture.
        $query->whereNotIn('status', ['completed', 'archived'])->triaged();

        match ($view) {
            'today' => $query->where(function ($query) use ($today): void {
                $query->where('workflow_state', Task::WORKFLOW_TODAY)
                    ->orWhereDate('due_date', '<=', $today);
            }),
            'this_week' => $query->where(function ($query) use ($today): void {
                $query->where('workflow_state', Task::WORKFLOW_THIS_WEEK)
                    ->orWhereBetween('due_date', [$today, app(OperationalClock::class)->dateString(7)]);
            }),
            'upcoming' => $query->whereDate('due_date', '>=', $today),
            'overdue' => $query->whereDate('due_date', '<', $today),
            'waiting' => $query->where('workflow_state', Task::WORKFLOW_WAITING),
            'delegated' => $query->where('workflow_state', Task::WORKFLOW_DELEGATED),
            'later' => $query->where('workflow_state', Task::WORKFLOW_LATER),
            default => null,
        };
    }

    /** Counts for the view tabs. One bounded COUNT per view, no relations. */
    private function viewCounts(Request $request, array $filters, string $today): array
    {
        $counts = [];

        foreach (array_keys($this->viewDefinitions()) as $view) {
            $query = $this->filteredTaskQuery($request, $filters, false);
            $this->applyView($query, $view, $today);
            $counts[$view] = $query->count();
        }

        return $counts;
    }

    /** @return array<int, array{value: string, label: string}> */
    private function workflowOptions(): array
    {
        return array_map(
            fn (string $state) => ['value' => $state, 'label' => Task::WORKFLOW_LABELS[$state] ?? $state],
            Task::ASSIGNABLE_WORKFLOW_STATES,
        );
    }

    private function filteredTaskQuery(Request $request, array $filters, bool $withRelations = true)
    {
        return Task::query()
            // Counting a view does not need seven relations loaded.
            ->when($withRelations, fn ($query) => $query->with([
                'project:id,name', 'area:id,name', 'portfolio:id,name', 'assignee:id,name', 'labels:id,name,color',
            ]))
            ->where(function ($query) use ($request): void {
                $query->where('assignee_id', $request->user()->id)
                    ->orWhere('reporter_id', $request->user()->id);
            })
            // The selected view decides whether archived work is included;
            // the base query no longer second-guesses it.
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['priority'] ?? null, fn ($query, string $priority) => $query->where('priority', $priority))
            ->when($filters['project_id'] ?? null, fn ($query, string $projectId) => $query->where('project_id', $projectId))
            ->when(
                in_array($filters['workflow_state'] ?? null, Task::WORKFLOW_STATES, true),
                fn ($query) => $query->where('workflow_state', $filters['workflow_state'])
            );
    }

    private function orderActiveTasks($query): void
    {
        $query
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->tap(fn ($query) => $this->orderByPriority($query))
            ->orderByDesc('updated_at');
    }

    private function orderByPriority($query): void
    {
        $query->orderByRaw("case priority when 'urgent' then 1 when 'high' then 2 when 'medium' then 3 when 'low' then 4 else 5 end");
    }

    private function orderCompletedTasks($query): void
    {
        $query
            ->orderByRaw('completed_at is null')
            ->orderByDesc('completed_at')
            ->orderByDesc('updated_at');
    }

    public function create(?Project $project = null): Response
    {
        Gate::authorize('create', Task::class);

        return Inertia::render('Tasks/Create', [
            'prefilledProject' => $project?->only(['id', 'workspace_id', 'area_id', 'portfolio_id', 'name']),
            ...$this->formOptions(),
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', Task::class);

        $data = $this->validatedTaskData($request);
        unset($data['label_ids'], $data['new_labels']);
        $data['reporter_id'] = $request->user()->id;
        $data['recurrence_type'] = $data['recurrence_type'] ?? 'none';
        $data['recurrence_interval'] = $data['recurrence_interval'] ?? 1;
        $data['completed_at'] = $data['status'] === 'completed' ? now() : null;

        $task = Task::create($data);
        $this->syncLabels($task, $request);
        $this->logActivity($task, $request->user()->id, 'task_created', 'Task was created.');
        // A task created with a due date must get a reminder, exactly like one
        // that acquires a due date through update()/status()/complete().
        app(MiriamReminderService::class)->syncAfterTaskSaved($task->refresh(), $request->user(), true);
        app(TaskCollaborationService::class)->notifyAssignment($task->loadMissing('assignee'), $request->user()->id, true);

        return redirect()
            ->route('tasks.show', $task)
            ->with('success', 'Task created.');
    }

    public function show(Task $task): Response
    {
        Gate::authorize('view', $task);

        $task->load([
            'workspace:id,name',
            'project:id,name',
            'area:id,name',
            'portfolio:id,name',
            'parentTask:id,title',
            'labels:id,name,color',
            'subtasks' => fn ($query) => $query
                ->with(['assignee:id,name', 'labels:id,name,color'])
                ->orderBy('position')
                ->oldest(),
            'assignee:id,name',
            'reporter:id,name',
            'comments' => fn ($query) => $query->with('user:id,name')->oldest(),
            'activities' => fn ($query) => $query->with('user:id,name')->latest(),
            'attachments' => fn ($query) => $query->with('user:id,name')->latest(),
        ]);

        $values = CustomFieldValue::query()
            ->where('entity_type', Task::class)
            ->where('entity_id', $task->id)
            ->get()
            ->keyBy('custom_field_id');

        return Inertia::render('Tasks/Show', [
            'task' => $this->taskResource($task),
            'customFields' => CustomField::query()
                ->where('workspace_id', $task->workspace_id)
                ->whereIn('applies_to', ['task', 'both'])
                ->orderBy('name')
                ->get()
                ->map(fn (CustomField $field) => [
                    'id' => $field->id,
                    'name' => $field->name,
                    'key' => $field->key,
                    'field_type' => $field->field_type,
                    'options' => $field->options ?? [],
                    'value' => $values->get($field->id)?->value,
                ])
                ->values(),
        ]);
    }

    public function edit(Task $task): Response
    {
        Gate::authorize('update', $task);

        $task->load(['workspace:id,name', 'project:id,name', 'area:id,name', 'portfolio:id,name', 'parentTask:id,title', 'labels:id,name,color', 'assignee:id,name', 'reporter:id,name']);

        return Inertia::render('Tasks/Edit', [
            'task' => $this->taskResource($task),
            'prefilledProject' => null,
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, Task $task)
    {
        Gate::authorize('update', $task);

        $original = $task->only(['status', 'priority', 'assignee_id', 'due_date', 'title']);
        $data = $this->validatedTaskData($request);
        unset($data['label_ids'], $data['new_labels']);
        $data['recurrence_type'] = $data['recurrence_type'] ?? 'none';
        $data['recurrence_interval'] = $data['recurrence_interval'] ?? 1;
        $data['completed_at'] = $data['status'] === 'completed'
            ? ($task->completed_at ?? now())
            : null;

        $task->update($data);
        $this->syncLabels($task, $request);
        $this->logImportantChanges($task->refresh(), $original, $request->user()->id);
        $this->createNextRecurringTaskIfNeeded($task, $original['status'] ?? null, $request->user()->id);
        app(MiriamReminderService::class)->syncAfterTaskSaved(
            $task->refresh(),
            $request->user(),
            $this->normalizeActivityValue($original['due_date'] ?? null) !== $this->normalizeActivityValue($task->due_date)
        );

        if (($original['assignee_id'] ?? null) !== $task->assignee_id) {
            app(TaskCollaborationService::class)->notifyAssignment($task->loadMissing('assignee'), $request->user()->id, true);
        }

        if (($original['status'] ?? null) !== $task->status) {
            $this->notifyAssignee($task, 'Task status changed', "{$task->title} moved to {$task->status}.");
        }

        return redirect()
            ->route('tasks.show', $task)
            ->with('success', 'Task updated.');
    }

    public function complete(Task $task)
    {
        Gate::authorize('update', $task);

        $oldStatus = $task->status;

        try {
            // Same domain transition the Inbox, Today and Slack use, so the
            // task leaves every active list the moment it is completed.
            app(TaskTransitionService::class)->apply(
                $task,
                TaskTransitionService::COMPLETE,
                request()->user(),
                ['source' => 'task_page', 'reason' => 'Task was marked complete.']
            );
        } catch (InvalidTaskTransitionException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if ($oldStatus !== 'completed') {
            app(TaskCollaborationService::class)->notifyCompletion($task->loadMissing(['reporter', 'project.owner', 'project.members']), request()->user());
        }
        $this->createNextRecurringTaskIfNeeded($task, $oldStatus ?? null, request()->user()->id);

        // Return where the operator was. Completing from Today used to eject
        // them onto the task detail page on every single tick.
        return back(fallback: route('tasks.show', $task))
            ->with('success', 'Task completed.');
    }

    public function status(Request $request, Task $task)
    {
        Gate::authorize('update', $task);

        $data = $request->validate([
            'status' => ['required', Rule::in(Task::BOARD_STATUSES)],
        ]);
        $oldStatus = $task->status;

        $task->update([
            'status' => $data['status'],
            'completed_at' => $data['status'] === 'completed' ? ($task->completed_at ?? now()) : null,
        ]);
        $this->logActivity(
            $task,
            $request->user()->id,
            'status_changed',
            "Status changed from {$oldStatus} to {$data['status']}.",
            $oldStatus,
            $data['status'],
        );
        $this->notifyAssignee($task, 'Task status changed', "{$task->title} moved from {$oldStatus} to {$data['status']}.");
        if ($oldStatus !== 'completed' && $task->status === 'completed') {
            app(TaskCollaborationService::class)->notifyCompletion($task->loadMissing(['reporter', 'project.owner', 'project.members']), $request->user());
        }
        $this->createNextRecurringTaskIfNeeded($task, $oldStatus, $request->user()->id);
        app(MiriamReminderService::class)->syncAfterTaskSaved($task->refresh(), $request->user());

        return back()->with('success', 'Task status updated.');
    }

    public function archive(Task $task)
    {
        Gate::authorize('delete', $task);

        $task->update(['status' => 'archived']);
        $this->logActivity($task, request()->user()->id, 'task_archived', 'Task was archived.');
        app(MiriamReminderService::class)->syncAfterTaskSaved($task->refresh(), request()->user());

        return redirect()
            ->route('tasks.index')
            ->with('success', 'Task archived.');
    }

    public function restore(Task $task)
    {
        Gate::authorize('update', $task);

        try {
            app(TaskTransitionService::class)->apply(
                $task,
                TaskTransitionService::REOPEN,
                request()->user(),
                ['source' => 'task_page', 'reason' => 'Task was reopened.']
            );
        } catch (InvalidTaskTransitionException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('tasks.show', $task)
            ->with('success', 'Task restored.');
    }

    private function validatedTaskData(Request $request): array
    {
        $workspaceIds = $this->writableWorkspaceIds($request->user());
        $userIds = $request->user()->workspaceUsersQuery()->pluck('id')->unique()->values()->all();

        return $request->validate([
            'workspace_id' => ['required', 'integer', Rule::exists('workspaces', 'id')->where(fn ($query) => $query->whereIn('id', $workspaceIds))],
            'area_id' => ['nullable', 'integer', Rule::exists('areas', 'id')],
            'portfolio_id' => ['nullable', 'integer', Rule::exists('portfolios', 'id')],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where(fn ($query) => $query->whereIn('workspace_id', $workspaceIds))],
            'parent_task_id' => ['nullable', 'integer', Rule::exists('tasks', 'id')->where(fn ($query) => $query->whereIn('workspace_id', $workspaceIds))],
            'task_type' => ['nullable', Rule::in(Task::TYPES)],
            'context' => ['nullable', 'string', 'max:255'],
            'energy_level' => ['nullable', 'string', 'max:255'],
            'focus_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            // `section` is the operator's own grouping label inside a project
            // ("Phase 4 - Sales Kit"). It carries no behaviour, and the form
            // offers existing labels rather than a bare text box.
            'section' => ['nullable', 'string', 'max:255'],
            // The daily workflow bucket. Strictly canonical - arbitrary strings
            // are rejected, because this one does drive behaviour.
            'workflow_state' => ['nullable', Rule::in(Task::ASSIGNABLE_WORKFLOW_STATES)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in(Task::STATUSES)],
            'priority' => ['required', Rule::in(Task::PRIORITIES)],
            'assignee_id' => ['nullable', 'integer', Rule::in($userIds)],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'recurrence_type' => ['nullable', Rule::in(Task::RECURRENCE_TYPES)],
            'recurrence_interval' => ['nullable', 'integer', 'min:1', 'max:12'],
            'recurrence_ends_at' => ['nullable', 'date', 'after_or_equal:due_date'],
            'label_ids' => ['nullable', 'array'],
            'label_ids.*' => ['integer', Rule::exists('labels', 'id')->where(fn ($query) => $query->whereIn('workspace_id', $workspaceIds))],
            'new_labels' => ['nullable', 'string', 'max:500'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function formOptions(): array
    {
        $user = request()->user();
        $workspaceIds = $user ? $this->writableWorkspaceIds($user) : [];

        return [
            'workspaces' => Workspace::query()->select(['id', 'name'])->whereIn('id', $workspaceIds)->orderBy('name')->get(),
            'areas' => Area::query()->select(['id', 'name'])->orderBy('position')->get(),
            'portfolios' => Portfolio::query()->select(['id', 'area_id', 'name'])->whereIn('workspace_id', $workspaceIds)->orderBy('name')->get(),
            'projects' => $this->accessibleProjects($workspaceIds)->select(['id', 'workspace_id', 'area_id', 'portfolio_id', 'name'])->orderBy('name')->get(),
            'users' => $user?->workspaceUsersQuery()->select(['id', 'name'])->distinct()->orderBy('name')->get() ?? collect(),
            'labels' => Label::query()
                ->select(['id', 'workspace_id', 'name', 'color'])
                ->whereIn('workspace_id', $workspaceIds)
                ->orderBy('name')
                ->get(),
            'statuses' => Task::STATUSES,
            'priorities' => Task::PRIORITIES,
            'taskTypes' => Task::TYPES,
            'recurrenceTypes' => Task::RECURRENCE_TYPES,
            'workflowStates' => $this->workflowOptions(),
            // Grouping labels that already exist, so the form suggests real
            // sections instead of inviting a free-text typo.
            'sections' => Task::query()
                ->whereIn('workspace_id', $workspaceIds)
                ->whereNotNull('section')
                ->distinct()
                ->orderBy('section')
                ->limit(200)
                ->pluck('section')
                ->values(),
        ];
    }

    private function accessibleProjects(array $workspaceIds)
    {
        return Project::query()
            ->when(
                $workspaceIds !== [],
                fn ($query) => $query->whereIn('workspace_id', $workspaceIds),
                fn ($query) => $query->whereRaw('1 = 0'),
            );
    }

    private function writableWorkspaceIds(User $user): array
    {
        return collect($user->accessibleWorkspaceIds())
            ->filter(fn (int $workspaceId) => $user->canWriteWorkspace($workspaceId))
            ->values()
            ->all();
    }

    private function taskResource(Task $task): array
    {
        return [
            'id' => $task->id,
            'area_id' => $task->area_id,
            'portfolio_id' => $task->portfolio_id,
            'workspace_id' => $task->workspace_id,
            'project_id' => $task->project_id,
            'parent_task_id' => $task->parent_task_id,
            'task_type' => $task->task_type,
            'context' => $task->context,
            'energy_level' => $task->energy_level,
            'focus_score' => $task->focus_score,
            'section' => $task->section,
            'workflow_state' => $task->workflow_state,
            // The interface shows this, never the raw value.
            'workflow_label' => Task::workflowLabel($task->workflow_state),
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'priority' => $task->priority,
            'assignee_id' => $task->assignee_id,
            'reporter_id' => $task->reporter_id,
            'start_date' => $task->start_date?->toDateString(),
            'due_date' => $task->due_date?->toDateString(),
            'recurrence_type' => $task->recurrence_type ?? 'none',
            'recurrence_interval' => $task->recurrence_interval ?? 1,
            'recurrence_ends_at' => $task->recurrence_ends_at?->toDateString(),
            'recurring_parent_id' => $task->recurring_parent_id,
            'last_generated_at' => $task->last_generated_at?->toDateTimeString(),
            'completed_at' => $task->completed_at?->toDateTimeString(),
            'position' => $task->position,
            'source' => $task->source,
            'source_metadata' => $task->source_metadata ?? [],
            'workspace' => $task->workspace ? [
                'id' => $task->workspace->id,
                'name' => $task->workspace->name,
            ] : null,
            'area' => $task->area ? [
                'id' => $task->area->id,
                'name' => $task->area->name,
            ] : null,
            'portfolio' => $task->portfolio ? [
                'id' => $task->portfolio->id,
                'name' => $task->portfolio->name,
            ] : null,
            'project' => $task->project ? [
                'id' => $task->project->id,
                'name' => $task->project->name,
            ] : null,
            'parent_task' => $task->parentTask ? [
                'id' => $task->parentTask->id,
                'title' => $task->parentTask->title,
            ] : null,
            'assignee' => $task->assignee ? [
                'id' => $task->assignee->id,
                'name' => $task->assignee->name,
            ] : null,
            'reporter' => $task->reporter ? [
                'id' => $task->reporter->id,
                'name' => $task->reporter->name,
            ] : null,
            'labels' => $task->relationLoaded('labels')
                ? $task->labels->map(fn (Label $label) => [
                    'id' => $label->id,
                    'workspace_id' => $label->workspace_id,
                    'name' => $label->name,
                    'color' => $label->color,
                ])->values()
                : [],
            'subtasks' => $task->relationLoaded('subtasks')
                ? $task->subtasks->map(fn (Task $subtask) => $this->taskResource($subtask))->values()
                : [],
            'subtask_progress' => $task->relationLoaded('subtasks')
                ? [
                    'total' => $task->subtasks->count(),
                    'completed' => $task->subtasks->where('status', 'completed')->count(),
                ]
                : ['total' => 0, 'completed' => 0],
            'comments' => $task->relationLoaded('comments')
                ? $task->comments->map(fn ($comment) => [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'created_at' => $comment->created_at?->toDateTimeString(),
                    'updated_at' => $comment->updated_at?->toDateTimeString(),
                    'can_manage' => $comment->user_id === request()->user()?->id,
                    'user' => $comment->user ? [
                        'id' => $comment->user->id,
                        'name' => $comment->user->name,
                    ] : null,
                ])->values()
                : [],
            'activities' => $task->relationLoaded('activities')
                ? $task->activities->map(fn ($activity) => [
                    'id' => $activity->id,
                    'action' => $activity->action,
                    'description' => $activity->description,
                    'old_value' => $activity->old_value,
                    'new_value' => $activity->new_value,
                    'created_at' => $activity->created_at?->toDateTimeString(),
                    'user' => $activity->user ? [
                        'id' => $activity->user->id,
                        'name' => $activity->user->name,
                    ] : null,
                ])->values()
                : [],
            'attachments' => $task->relationLoaded('attachments')
                ? $task->attachments->map(fn ($attachment) => [
                    'id' => $attachment->id,
                    'original_name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'size' => $attachment->size,
                    'created_at' => $attachment->created_at?->toDateTimeString(),
                    'can_delete' => $attachment->user_id === request()->user()?->id,
                    'download_url' => route('task-attachments.download', $attachment),
                    'user' => $attachment->user ? [
                        'id' => $attachment->user->id,
                        'name' => $attachment->user->name,
                    ] : null,
                ])->values()
                : [],
        ];
    }

    private function logImportantChanges(Task $task, array $original, int $userId): void
    {
        $fields = [
            'status' => 'Status',
            'priority' => 'Priority',
            'assignee_id' => 'Assignee',
            'due_date' => 'Due date',
            'title' => 'Title',
        ];

        foreach ($fields as $field => $label) {
            $oldValue = $this->normalizeActivityValue($original[$field] ?? null);
            $newValue = $this->normalizeActivityValue($task->{$field});

            if ($oldValue === $newValue) {
                continue;
            }

            $this->logActivity(
                $task,
                $userId,
                "{$field}_changed",
                "{$label} changed.",
                $oldValue,
                $newValue,
            );
        }
    }

    private function logActivity(
        Task $task,
        ?int $userId,
        string $action,
        ?string $description = null,
        ?string $oldValue = null,
        ?string $newValue = null,
    ): void {
        $task->activities()->create([
            'user_id' => $userId,
            'action' => $action,
            'description' => $description,
            'old_value' => $oldValue,
            'new_value' => $newValue,
        ]);
    }

    private function syncLabels(Task $task, Request $request): void
    {
        $labelIds = collect($request->input('label_ids', []))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();

        $existingIds = Label::query()
            ->where('workspace_id', $task->workspace_id)
            ->whereIn('id', $labelIds)
            ->pluck('id');

        $newLabelIds = collect(explode(',', (string) $request->input('new_labels', '')))
            ->map(fn (string $name) => trim($name))
            ->filter()
            ->unique(fn (string $name) => mb_strtolower($name))
            ->map(function (string $name) use ($task) {
                return Label::query()->firstOrCreate(
                    ['workspace_id' => $task->workspace_id, 'name' => $name],
                    ['color' => '#475569'],
                )->id;
            });

        $task->labels()->sync($existingIds->merge($newLabelIds)->unique()->values()->all());
    }

    private function createNextRecurringTaskIfNeeded(Task $task, ?string $oldStatus, ?int $userId): void
    {
        if ($oldStatus === 'completed' || $task->status !== 'completed') {
            return;
        }

        app(RecurringTaskService::class)->createNextOccurrence($task->refresh(), $userId);
    }

    private function normalizeActivityValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }

    private function notifyAssignee(Task $task, string $title, string $message, bool $sendMail = false): void
    {
        if (! $task->assignee_id) {
            return;
        }

        $task->loadMissing('assignee');

        $task->assignee?->notify(new TaskFlowNotification(
            title: $title,
            message: $message,
            taskId: $task->id,
            projectId: $task->project_id,
            actionUrl: route('tasks.show', $task, false),
            sendMail: $sendMail,
            eventType: 'task_status',
        ));
    }
}
