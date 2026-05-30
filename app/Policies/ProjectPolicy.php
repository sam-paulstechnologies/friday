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
        return collect($user->accessibleWorkspaceIds())
            ->contains(fn (int $workspaceId) => $user->canWriteWorkspace($workspaceId));
    }

    public function update(User $user, Project $project): bool
    {
        return $this->view($user, $project)
            && $user->canWriteWorkspace($project->workspace_id)
            && (
                $user->hasWorkspaceRole($project->workspace_id, ['owner', 'admin'])
                || $project->owner_id === $user->id
            );
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }
}
