<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Models\TaskReminder;
use App\Notifications\TaskFlowNotification;
use Illuminate\Console\Command;

class SendTaskReminders extends Command
{
    protected $signature = 'taskflow:send-task-reminders';

    protected $description = 'Send due soon and overdue task reminders.';

    public function handle(): int
    {
        $today = now()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        Task::query()
            ->with(['assignee:id,name,email'])
            ->whereNotNull('assignee_id')
            ->whereNotNull('due_date')
            ->whereNotIn('status', ['completed', 'archived'])
            ->get()
            ->each(function (Task $task) use ($today, $tomorrow): void {
                $dueDate = $task->due_date?->toDateString();
                $type = match (true) {
                    $dueDate === $tomorrow => 'due_tomorrow',
                    $dueDate === $today => 'due_today',
                    $dueDate < $today => 'overdue',
                    default => null,
                };

                if (! $type || ! $task->assignee) {
                    return;
                }

                $exists = TaskReminder::query()
                    ->where('task_id', $task->id)
                    ->where('user_id', $task->assignee_id)
                    ->where('reminder_type', $type)
                    ->whereDate('reminder_date', $today)
                    ->exists();

                if ($exists) {
                    return;
                }

                TaskReminder::create([
                    'task_id' => $task->id,
                    'user_id' => $task->assignee_id,
                    'reminder_type' => $type,
                    'reminder_date' => $today,
                ]);

                $task->assignee->notify(new TaskFlowNotification(
                    title: 'Task reminder',
                    message: "{$task->title} is {$this->label($type)}.",
                    taskId: $task->id,
                    projectId: $task->project_id,
                    actionUrl: route('tasks.show', $task, false),
                    sendMail: true,
                    eventType: 'task_reminder',
                ));
            });

        $this->info('Task reminders processed.');

        return self::SUCCESS;
    }

    private function label(string $type): string
    {
        return match ($type) {
            'due_tomorrow' => 'due tomorrow',
            'due_today' => 'due today',
            'overdue' => 'overdue',
            default => 'due',
        };
    }
}
