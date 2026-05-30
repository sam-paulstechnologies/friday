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
        return $user->accessibleWorkspaceIds() !== [];
    }

    public function update(User $user, Portfolio $portfolio): bool
    {
        return $this->view($user, $portfolio);
    }

    public function delete(User $user, Portfolio $portfolio): bool
    {
        return $this->update($user, $portfolio);
    }
}
