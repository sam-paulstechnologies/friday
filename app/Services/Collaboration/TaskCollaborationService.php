<?php

namespace App\Services\Collaboration;

use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use App\Notifications\TaskFlowNotification;
use Illuminate\Support\Collection;

class TaskCollaborationService
{
    public function notifyAssignment(Task $task, ?int $actorId = null, bool $sendMail = false): void
    {
        $recipient = $task->assignee;

        if (! $recipient || $recipient->id === $actorId) {
            return;
        }

        $recipient->notify(new TaskFlowNotification(
            title: 'Task assigned',
            message: "You were assigned to {$task->title}.",
            taskId: $task->id,
            projectId: $task->project_id,
            actionUrl: route('tasks.show', $task, false),
            sendMail: $sendMail,
            eventType: 'task_assigned',
        ));
    }

    public function notifyComment(Task $task, TaskComment $comment, User $actor): void
    {
        $this->taskParticipants($task)
            ->merge($this->mentionedUsers($task, $comment->body))
            ->unique('id')
            ->reject(fn (User $user) => $user->id === $actor->id)
            ->each(fn (User $user) => $user->notify(new TaskFlowNotification(
                title: 'Comment added',
                message: "{$actor->name} commented on {$task->title}.",
                taskId: $task->id,
                projectId: $task->project_id,
                actionUrl: route('tasks.show', $task, false),
                eventType: 'task_comment',
            )));
    }

    public function notifyCompletion(Task $task, User $actor): void
    {
        $this->taskParticipants($task)
            ->merge($task->project?->owner ? collect([$task->project->owner]) : collect())
            ->unique('id')
            ->reject(fn (User $user) => $user->id === $actor->id)
            ->each(fn (User $user) => $user->notify(new TaskFlowNotification(
                title: 'Task completed',
                message: "{$actor->name} completed {$task->title}.",
                taskId: $task->id,
                projectId: $task->project_id,
                actionUrl: route('tasks.show', $task, false),
                eventType: 'task_completed',
            )));
    }

    public function mentionedUsers(Task $task, string $body): Collection
    {
        preg_match_all('/@([A-Za-z0-9._%+\-@]+)/', $body, $matches);
        $tokens = collect($matches[1] ?? [])
            ->map(fn (string $token) => trim($token, " \t\n\r\0\x0B.,;:!?()[]{}<>"))
            ->filter()
            ->unique(fn (string $token) => mb_strtolower($token));

        if ($tokens->isEmpty()) {
            return collect();
        }

        $workspaceUsers = $task->workspace?->users() ?? User::query()->whereRaw('1 = 0');

        return $workspaceUsers
            ->where(function ($query) use ($tokens): void {
                foreach ($tokens as $token) {
                    $query->orWhere('email', $token)
                        ->orWhere('name', 'like', str_replace(['%', '_'], ['\%', '\_'], $token).'%');
                }
            })
            ->get()
            ->unique('id')
            ->values();
    }

    private function taskParticipants(Task $task): Collection
    {
        $task->loadMissing(['assignee', 'reporter', 'project.owner', 'project.members']);

        return collect([
            $task->assignee,
            $task->reporter,
            $task->project?->owner,
        ])
            ->merge($task->project?->members ?? collect())
            ->filter()
            ->unique('id')
            ->values();
    }
}
