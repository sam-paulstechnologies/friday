<?php

namespace App\Policies;

use App\Models\Portfolio;
use App\Models\User;

class PortfolioPolicy
{
    public function view(User $user, Portfolio $portfolio): bool
    {
        return $user->canAccessWorkspace($portfolio->workspace_id);
    }

    public function create(User $user): bool
    {
        return collect($user->accessibleWorkspaceIds())
            ->contains(fn (int $workspaceId) => $user->hasWorkspaceRole($workspaceId, ['owner', 'admin']));
    }

    public function update(User $user, Portfolio $portfolio): bool
    {
        return $this->view($user, $portfolio)
            && $user->hasWorkspaceRole($portfolio->workspace_id, ['owner', 'admin']);
    }

    public function delete(User $user, Portfolio $portfolio): bool
    {
        return $this->update($user, $portfolio);
    }
}
