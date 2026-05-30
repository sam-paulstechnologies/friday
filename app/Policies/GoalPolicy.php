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
        return $user->accessibleWorkspaceIds() !== [];
    }

    public function update(User $user, Goal $goal): bool
    {
        return $this->view($user, $goal);
    }

    public function delete(User $user, Goal $goal): bool
    {
        return $this->update($user, $goal);
    }
}
