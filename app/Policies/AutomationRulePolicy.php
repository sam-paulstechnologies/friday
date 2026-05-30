<?php

namespace App\Policies;

use App\Models\AutomationRule;
use App\Models\User;
use App\Models\Workspace;

class AutomationRulePolicy
{
    public function view(User $user, AutomationRule $rule): bool
    {
        return $user->canAccessWorkspace($rule->workspace_id);
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return $user->canManageWorkspace($workspace->id);
    }

    public function update(User $user, AutomationRule $rule): bool
    {
        return $this->view($user, $rule)
            && $user->canManageWorkspace($rule->workspace_id);
    }

    public function delete(User $user, AutomationRule $rule): bool
    {
        return $this->update($user, $rule);
    }
}
