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
        return $user->canManageWorkspace($workspace->id);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $user->hasWorkspaceRole($workspace->id, ['owner']);
    }

    public function viewSettings(User $user, Workspace $workspace): bool
    {
        return $user->canManageWorkspace($workspace->id);
    }

    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $user->canManageWorkspace($workspace->id);
    }

    public function manageRoles(User $user, Workspace $workspace): bool
    {
        return $user->canManageWorkspace($workspace->id);
    }
}
