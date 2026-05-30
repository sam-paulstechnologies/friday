<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskComment;
use App\Notifications\TaskFlowNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TaskCommentController extends Controller
{
    public function store(Request $request, Task $task)
    {
        Gate::authorize('view', $task);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $task->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        $task->loadMissing('assignee');

        $task->assignee?->notify(new TaskFlowNotification(
            title: 'Comment added',
            message: "{$request->user()->name} commented on {$task->title}.",
            taskId: $task->id,
            projectId: $task->project_id,
            actionUrl: route('tasks.show', $task, false),
            sendMail: true,
        ));

        return back()->with('success', 'Comment added.');
    }

    public function update(Request $request, TaskComment $comment)
    {
        Gate::authorize('view', $comment->task);
        abort_unless($comment->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $comment->update($data);

        return back()->with('success', 'Comment updated.');
    }

    public function destroy(Request $request, TaskComment $comment)
    {
        Gate::authorize('view', $comment->task);
        abort_unless($comment->user_id === $request->user()->id, 403);

        $comment->delete();

        return back()->with('success', 'Comment deleted.');
    }
}
