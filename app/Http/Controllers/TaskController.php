<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\TaskFlowNotification;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'priority', 'project_id']);

        $tasks = Task::query()
            ->with(['workspace:id,name', 'project:id,name', 'area:id,name', 'portfolio:id,name', 'assignee:id,name', 'reporter:id,name'])
            ->where(function ($query) use ($request): void {
                $query->where('assignee_id', $request->user()->id)
                    ->orWhere('reporter_id', $request->user()->id);
            })
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['priority'] ?? null, fn ($query, string $priority) => $query->where('priority', $priority))
            ->when($filters['project_id'] ?? null, fn ($query, string $projectId) => $query->where('project_id', $projectId))
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderBy('position')
            ->latest()
            ->get()
            ->map(fn (Task $task) => $this->taskResource($task));

        return Inertia::render('Tasks/Index', [
            'tasks' => $tasks,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? '',
                'priority' => $filters['priority'] ?? '',
                'project_id' => $filters['project_id'] ?? '',
            ],
            'statuses' => Task::STATUSES,
            'priorities' => Task::PRIORITIES,
            'projects' => Project::query()->select(['id', 'name'])->orderBy('name')->get(),
        ]);
    }

    public function create(?Project $project = null): Response
    {
        return Inertia::render('Tasks/Create', [
            'prefilledProject' => $project?->only(['id', 'workspace_id', 'area_id', 'portfolio_id', 'name']),
            ...$this->formOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validatedTaskData($request);
        $data['reporter_id'] = $request->user()->id;
        $data['completed_at'] = $data['status'] === 'completed' ? now() : null;

        $task = Task::create($data);
        $this->logActivity($task, $request->user()->id, 'task_created', 'Task was created.');
        $this->notifyAssignee($task, 'Task assigned', "You were assigned to {$task->title}.", true);

        return redirect()
            ->route('tasks.show', $task)
            ->with('success', 'Task created.');
    }

    public function show(Task $task): Response
    {
        $task->load([
            'workspace:id,name',
            'project:id,name',
            'area:id,name',
            'portfolio:id,name',
            'parentTask:id,title',
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
        $task->load(['workspace:id,name', 'project:id,name', 'area:id,name', 'portfolio:id,name', 'parentTask:id,title', 'assignee:id,name', 'reporter:id,name']);

        return Inertia::render('Tasks/Edit', [
            'task' => $this->taskResource($task),
            'prefilledProject' => null,
            ...$this->formOptions(),
        ]);
    }

    public function update(Request $request, Task $task)
    {
        $original = $task->only(['status', 'priority', 'assignee_id', 'due_date', 'title']);
        $data = $this->validatedTaskData($request);
        $data['completed_at'] = $data['status'] === 'completed'
            ? ($task->completed_at ?? now())
            : null;

        $task->update($data);
        $this->logImportantChanges($task->refresh(), $original, $request->user()->id);

        if (($original['assignee_id'] ?? null) !== $task->assignee_id) {
            $this->notifyAssignee($task, 'Task assigned', "You were assigned to {$task->title}.", true);
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
        $task->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        $this->logActivity($task, request()->user()->id, 'task_completed', 'Task was marked complete.');

        return redirect()
            ->route('tasks.show', $task)
            ->with('success', 'Task completed.');
    }

    public function status(Request $request, Task $task)
    {
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

        return back()->with('success', 'Task status updated.');
    }

    public function archive(Task $task)
    {
        $task->update(['status' => 'archived']);
        $this->logActivity($task, request()->user()->id, 'task_archived', 'Task was archived.');

        return redirect()
            ->route('tasks.index')
            ->with('success', 'Task archived.');
    }

    private function validatedTaskData(Request $request): array
    {
        return $request->validate([
            'workspace_id' => ['required', 'integer', Rule::exists('workspaces', 'id')],
            'area_id' => ['nullable', 'integer', Rule::exists('areas', 'id')],
            'portfolio_id' => ['nullable', 'integer', Rule::exists('portfolios', 'id')],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')],
            'parent_task_id' => ['nullable', 'integer', Rule::exists('tasks', 'id')],
            'task_type' => ['nullable', Rule::in(Task::TYPES)],
            'context' => ['nullable', 'string', 'max:255'],
            'energy_level' => ['nullable', 'string', 'max:255'],
            'focus_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'section' => ['nullable', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::in(Task::STATUSES)],
            'priority' => ['required', Rule::in(Task::PRIORITIES)],
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function formOptions(): array
    {
        return [
            'workspaces' => Workspace::query()->select(['id', 'name'])->orderBy('name')->get(),
            'areas' => Area::query()->select(['id', 'name'])->orderBy('position')->get(),
            'portfolios' => Portfolio::query()->select(['id', 'area_id', 'name'])->orderBy('name')->get(),
            'projects' => Project::query()->select(['id', 'workspace_id', 'area_id', 'portfolio_id', 'name'])->orderBy('name')->get(),
            'users' => User::query()->select(['id', 'name'])->orderBy('name')->get(),
            'statuses' => Task::STATUSES,
            'priorities' => Task::PRIORITIES,
            'taskTypes' => Task::TYPES,
        ];
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
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'priority' => $task->priority,
            'assignee_id' => $task->assignee_id,
            'reporter_id' => $task->reporter_id,
            'start_date' => $task->start_date?->toDateString(),
            'due_date' => $task->due_date?->toDateString(),
            'completed_at' => $task->completed_at?->toDateTimeString(),
            'position' => $task->position,
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
        ));
    }
}
