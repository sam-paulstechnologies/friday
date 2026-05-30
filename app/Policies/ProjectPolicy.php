<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $user->canAccessWorkspace($project->workspace_id)
            || $project->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->accessibleWorkspaceIds() !== [];
    }

    public function update(User $user, Project $project): bool
    {
        return $this->view($user, $project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }
}
