<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Notifications\TaskFlowNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;

class TaskAttachmentController extends Controller
{
    public function store(Request $request, Task $task)
    {
        $data = $request->validate([
            'file' => [
                'required',
                File::types(['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'webp', 'txt', 'csv'])
                    ->max(10 * 1024),
            ],
        ]);

        $file = $data['file'];
        $storedName = Str::uuid().'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs("task-attachments/{$task->id}", $storedName, 'attachments');

        $attachment = $task->attachments()->create([
            'user_id' => $request->user()->id,
            'original_name' => $file->getClientOriginalName(),
            'stored_name' => $storedName,
            'path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);

        $task->activities()->create([
            'user_id' => $request->user()->id,
            'action' => 'attachment_uploaded',
            'description' => "Uploaded {$attachment->original_name}.",
            'new_value' => $attachment->original_name,
        ]);

        $task->loadMissing('assignee');

        $task->assignee?->notify(new TaskFlowNotification(
            title: 'Attachment uploaded',
            message: "{$request->user()->name} uploaded {$attachment->original_name} to {$task->title}.",
            taskId: $task->id,
            projectId: $task->project_id,
            actionUrl: route('tasks.show', $task, false),
        ));

        return back()->with('success', 'Attachment uploaded.');
    }

    public function download(TaskAttachment $attachment)
    {
        abort_unless(Storage::disk('attachments')->exists($attachment->path), 404);

        return Storage::disk('attachments')->download(
            $attachment->path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type ?: 'application/octet-stream'],
        );
    }

    public function destroy(Request $request, TaskAttachment $attachment)
    {
        abort_unless($attachment->user_id === $request->user()->id, 403);

        $task = $attachment->task;
        $originalName = $attachment->original_name;

        Storage::disk('attachments')->delete($attachment->path);
        $attachment->delete();

        $task->activities()->create([
            'user_id' => $request->user()->id,
            'action' => 'attachment_deleted',
            'description' => "Deleted {$originalName}.",
            'old_value' => $originalName,
        ]);

        return back()->with('success', 'Attachment deleted.');
    }
}
