<?php

namespace App\Services\Tasks;

use App\Models\Task;
use Carbon\CarbonInterface;

class RecurringTaskService
{
    public function createNextOccurrence(Task $task, ?int $userId = null): ?Task
    {
        if (! in_array($task->recurrence_type, Task::RECURRENCE_TYPES, true) || $task->recurrence_type === 'none') {
            return null;
        }

        $baseDate = $task->due_date ?? $task->completed_at;

        if (! $baseDate) {
            return null;
        }

        $nextDueDate = $this->nextDueDate($baseDate, $task->recurrence_type, max(1, (int) $task->recurrence_interval));

        if ($task->recurrence_ends_at && $nextDueDate->toDateString() > $task->recurrence_ends_at->toDateString()) {
            return null;
        }

        $seriesRootId = $task->recurring_parent_id ?: $task->id;
        $alreadyExists = Task::query()
            ->where('recurring_parent_id', $seriesRootId)
            ->whereDate('due_date', $nextDueDate->toDateString())
            ->exists();

        if ($alreadyExists) {
            return null;
        }

        $nextTask = Task::create([
            'area_id' => $task->area_id,
            'portfolio_id' => $task->portfolio_id,
            'workspace_id' => $task->workspace_id,
            'project_id' => $task->project_id,
            'parent_task_id' => $task->parent_task_id,
            'task_type' => $task->task_type,
            'context' => $task->context,
            'energy_level' => $task->energy_level,
            'focus_score' => $task->focus_score,
            'section' => $task->section,
            'title' => $task->title,
            'description' => $task->description,
            'status' => 'todo',
            'priority' => $task->priority,
            'assignee_id' => $task->assignee_id,
            'reporter_id' => $task->reporter_id,
            'start_date' => $nextDueDate->toDateString(),
            'due_date' => $nextDueDate->toDateString(),
            'position' => $task->position,
            'recurrence_type' => $task->recurrence_type,
            'recurrence_interval' => $task->recurrence_interval,
            'recurrence_ends_at' => $task->recurrence_ends_at,
            'recurring_parent_id' => $seriesRootId,
        ]);

        $task->loadMissing('labels');
        $nextTask->labels()->sync($task->labels->pluck('id')->all());
        $task->forceFill(['last_generated_at' => now()])->save();

        $nextTask->activities()->create([
            'user_id' => $userId,
            'action' => 'recurring_task_generated',
            'description' => 'Next recurring task occurrence was created.',
            'old_value' => (string) $task->id,
            'new_value' => $nextDueDate->toDateString(),
        ]);

        return $nextTask;
    }

    private function nextDueDate(CarbonInterface $baseDate, string $type, int $interval): CarbonInterface
    {
        return match ($type) {
            'daily' => $baseDate->copy()->addDays($interval),
            'weekly' => $baseDate->copy()->addWeeks($interval),
            'monthly' => $baseDate->copy()->addMonthsNoOverflow($interval),
            default => $baseDate->copy(),
        };
    }
}
