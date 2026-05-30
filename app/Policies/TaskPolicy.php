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
        return $user->accessibleWorkspaceIds() !== [];
    }

    public function update(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }
}
