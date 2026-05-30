<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskComment;
use App\Services\Collaboration\TaskCollaborationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TaskCommentController extends Controller
{
    public function store(Request $request, Task $task, TaskCollaborationService $collaboration)
    {
        Gate::authorize('view', $task);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $comment = $task->comments()->create([
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        $task->activities()->create([
            'user_id' => $request->user()->id,
            'action' => 'comment_added',
            'description' => 'Comment was added.',
            'new_value' => (string) str($data['body'])->limit(160),
        ]);

        $collaboration->notifyComment($task, $comment, $request->user());

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
