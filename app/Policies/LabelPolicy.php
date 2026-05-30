<?php

namespace App\Policies;

use App\Models\Label;
use App\Models\User;
use App\Models\Workspace;

class LabelPolicy
{
    public function view(User $user, Label $label): bool
    {
        return $user->canAccessWorkspace($label->workspace_id);
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return $user->canWriteWorkspace($workspace->id);
    }

    public function update(User $user, Label $label): bool
    {
        return $this->view($user, $label)
            && $user->canWriteWorkspace($label->workspace_id);
    }

    public function delete(User $user, Label $label): bool
    {
        return $this->update($user, $label);
    }
}
