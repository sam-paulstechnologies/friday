<?php

namespace App\Policies;

use App\Models\Goal;
use App\Models\User;

class GoalPolicy
{
    public function view(User $user, Goal $goal): bool
    {
        return $user->canAccessWorkspace($goal->workspace_id);
    }

    public function create(User $user): bool
    {
        return collect($user->accessibleWorkspaceIds())
            ->contains(fn (int $workspaceId) => $user->hasWorkspaceRole($workspaceId, ['owner', 'admin']));
    }

    public function update(User $user, Goal $goal): bool
    {
        return $this->view($user, $goal)
            && $user->hasWorkspaceRole($goal->workspace_id, ['owner', 'admin']);
    }

    public function delete(User $user, Goal $goal): bool
    {
        return $this->update($user, $goal);
    }
}
