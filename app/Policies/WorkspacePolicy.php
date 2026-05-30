<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    public function view(User $user, Workspace $workspace): bool
    {
        return $user->canAccessWorkspace($workspace->id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $workspace->created_by === $user->id
            || $workspace->users()
                ->whereKey($user->id)
                ->wherePivotIn('role', ['owner', 'admin'])
                ->exists();
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $this->update($user, $workspace);
    }
}
