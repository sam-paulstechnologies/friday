<?php

namespace App\Services\Ai;

use App\Models\AiAction;
use App\Models\AuditLog;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AiAssistantActionService
{
    public function createTask(User $user, array $payload): AiAction
    {
        Gate::forUser($user)->authorize('create', Task::class);

        $workspaceId = (int) ($payload['workspace_id'] ?? 0);

        if (! $user->canWriteWorkspace($workspaceId)) {
            throw ValidationException::withMessages([
                'workspace_id' => 'You cannot create tasks in this workspace.',
            ]);
        }

        $action = AiAction::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'action_type' => 'create_task',
            'status' => AiAction::STATUS_PROPOSED,
            'payload' => $this->safePayload($payload),
        ]);

        try {
            $task = Task::create([
                'workspace_id' => $workspaceId,
                'project_id' => $payload['project_id'] ?? null,
                'title' => $payload['title'],
                'description' => $payload['description'] ?? null,
                'status' => 'todo',
                'priority' => $payload['priority'] ?? 'medium',
                'assignee_id' => $payload['assignee_id'] ?? $user->id,
                'reporter_id' => $user->id,
                'due_date' => $payload['due_date'] ?? null,
                'recurrence_type' => 'none',
                'recurrence_interval' => 1,
            ]);

            $action->update([
                'status' => AiAction::STATUS_EXECUTED,
                'target_type' => Task::class,
                'target_id' => $task->id,
            ]);

            $task->activities()->create([
                'user_id' => $user->id,
                'action' => 'task_created_by_assistant',
                'description' => 'Task was created from Miriam Assistant.',
            ]);

            AuditLog::record($workspaceId, $user->id, 'ai_task_created', $task, [
                'task_title' => $task->title,
                'ai_action_id' => $action->id,
            ]);

            return $action->refresh();
        } catch (\Throwable $exception) {
            $action->update(['status' => AiAction::STATUS_FAILED]);

            throw $exception;
        }
    }

    private function safePayload(array $payload): array
    {
        return collect($payload)
            ->only(['workspace_id', 'project_id', 'title', 'description', 'priority', 'assignee_id', 'due_date'])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();
    }
}
