<?php

namespace App\Policies;

use App\Models\CustomField;
use App\Models\User;
use App\Models\Workspace;

class CustomFieldPolicy
{
    public function view(User $user, CustomField $customField): bool
    {
        return $user->canAccessWorkspace($customField->workspace_id);
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return $user->canManageWorkspace($workspace->id);
    }

    public function update(User $user, CustomField $customField): bool
    {
        return $this->view($user, $customField)
            && $user->canManageWorkspace($customField->workspace_id);
    }

    public function delete(User $user, CustomField $customField): bool
    {
        return $this->update($user, $customField);
    }
}
