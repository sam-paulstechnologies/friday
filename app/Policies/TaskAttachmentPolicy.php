<?php

namespace App\Policies;

use App\Models\TaskAttachment;
use App\Models\User;

class TaskAttachmentPolicy
{
    public function view(User $user, TaskAttachment $attachment): bool
    {
        return $attachment->task !== null
            && $user->can('view', $attachment->task);
    }

    public function delete(User $user, TaskAttachment $attachment): bool
    {
        return $attachment->user_id === $user->id
            && $this->view($user, $attachment);
    }
}
