<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function view(User $user, Task $task): bool
    {
        return $user->canAccessWorkspace($task->workspace_id)
            || $task->assignee_id === $user->id
            || $task->reporter_id === $user->id;
    }

    public function create(User $user): bool
    {
        return collect($user->accessibleWorkspaceIds())
            ->contains(fn (int $workspaceId) => $user->canWriteWorkspace($workspaceId));
    }

    public function update(User $user, Task $task): bool
    {
        if (! $this->view($user, $task) || ! $user->canWriteWorkspace($task->workspace_id)) {
            return false;
        }

        return $user->hasWorkspaceRole($task->workspace_id, ['owner', 'admin'])
            || $task->assignee_id === $user->id
            || $task->reporter_id === $user->id
            || $task->project?->members()->whereKey($user->id)->exists();
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }
}
