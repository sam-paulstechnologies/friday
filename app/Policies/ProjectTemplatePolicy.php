<?php

namespace App\Policies;

use App\Models\ProjectTemplate;
use App\Models\User;
use App\Models\Workspace;

class ProjectTemplatePolicy
{
    public function view(User $user, ProjectTemplate $template): bool
    {
        return $user->canAccessWorkspace($template->workspace_id);
    }

    public function create(User $user, Workspace $workspace): bool
    {
        return $user->canAccessWorkspace($workspace->id);
    }

    public function update(User $user, ProjectTemplate $template): bool
    {
        return $this->view($user, $template);
    }

    public function delete(User $user, ProjectTemplate $template): bool
    {
        return $this->view($user, $template);
    }
}
