<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Services\Tasks\RecurringTaskService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TaskSubtaskController extends Controller
{
    public function store(Request $request, Task $task)
    {
        Gate::authorize('update', $task);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
        ]);

        $subtask = Task::create([
            'workspace_id' => $task->workspace_id,
            'project_id' => $task->project_id,
            'area_id' => $task->area_id,
            'portfolio_id' => $task->portfolio_id,
            'parent_task_id' => $task->id,
            'task_type' => 'task',
            'title' => $data['title'],
            'status' => 'todo',
            'priority' => $task->priority,
            'assignee_id' => $task->assignee_id,
            'reporter_id' => $request->user()->id,
            'due_date' => $data['due_date'] ?? null,
        ]);

        $subtask->activities()->create([
            'user_id' => $request->user()->id,
            'action' => 'subtask_created',
            'description' => 'Subtask was created.',
        ]);

        return back()->with('success', 'Subtask created.');
    }

    public function status(Request $request, Task $task, Task $subtask)
    {
        Gate::authorize('update', $task);
        Gate::authorize('update', $subtask);

        abort_unless((int) $subtask->parent_task_id === (int) $task->id, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(['todo', 'completed'])],
        ]);

        $oldStatus = $subtask->status;
        $subtask->update([
            'status' => $data['status'],
            'completed_at' => $data['status'] === 'completed' ? ($subtask->completed_at ?? now()) : null,
        ]);

        $subtask->activities()->create([
            'user_id' => $request->user()->id,
            'action' => 'status_changed',
            'description' => "Subtask status changed from {$oldStatus} to {$data['status']}.",
            'old_value' => $oldStatus,
            'new_value' => $data['status'],
        ]);

        if ($oldStatus !== 'completed' && $subtask->status === 'completed') {
            app(RecurringTaskService::class)->createNextOccurrence($subtask, $request->user()->id);
        }

        return back()->with('success', 'Subtask updated.');
    }
}
