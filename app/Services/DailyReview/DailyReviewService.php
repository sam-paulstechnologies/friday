<?php

namespace App\Services\DailyReview;

use App\Models\DailyReview;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;

class DailyReviewService
{
    public const CLOSED_STATUSES = ['completed', 'done', 'archived'];

    public function collectTodayTasks(User $user): Collection
    {
        $today = now()->toDateString();
        $nextWeek = now()->addDays(7)->toDateString();

        $tasks = Task::query()
            ->with(['workspace:id,name', 'project:id,name', 'area:id,name', 'portfolio:id,name'])
            ->where(function ($query) use ($user): void {
                $query->where('assignee_id', $user->id)
                    ->orWhere('reporter_id', $user->id);
            })
            ->whereNotIn('status', self::CLOSED_STATUSES)
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderByRaw("case priority when 'urgent' then 1 when 'high' then 2 when 'medium' then 3 else 4 end")
            ->orderBy('position')
            ->get();

        return collect([
            'overdue' => $tasks->filter(fn (Task $task) => $task->due_date && $task->due_date->toDateString() < $today && $task->status !== 'blocked' && $task->status !== 'waiting')->values(),
            'due_today' => $tasks->filter(fn (Task $task) => $task->due_date?->toDateString() === $today && $task->status !== 'blocked' && $task->status !== 'waiting')->values(),
            'upcoming' => $tasks->filter(fn (Task $task) => $task->due_date && $task->due_date->toDateString() > $today && $task->due_date->toDateString() <= $nextWeek && $task->status !== 'blocked' && $task->status !== 'waiting')->values(),
            'no_due_date' => $tasks->filter(fn (Task $task) => is_null($task->due_date) && ! in_array($task->status, ['blocked', 'waiting'], true))->values(),
            'blocked' => $tasks->filter(fn (Task $task) => $task->status === 'blocked')->values(),
            'waiting' => $tasks->filter(fn (Task $task) => $task->status === 'waiting')->values(),
        ]);
    }

    public function createMorningReview(User $user): DailyReview
    {
        return $this->createReview($user, 'morning');
    }

    public function createEveningReview(User $user): DailyReview
    {
        return $this->createReview($user, 'evening');
    }

    public function selectTopFocusItems(Collection $tasks): Collection
    {
        $overdue = $tasks->get('overdue', collect());
        $dueToday = $tasks->get('due_today', collect());
        $upcoming = $tasks->get('upcoming', collect());

        return collect()
            ->merge($overdue->whereIn('priority', ['urgent', 'high']))
            ->merge($dueToday->whereIn('priority', ['urgent', 'high']))
            ->merge($overdue->where('priority', 'medium'))
            ->merge($dueToday->where('priority', 'medium'))
            ->merge($upcoming->where('priority', 'high'))
            ->unique('id')
            ->take(3)
            ->values();
    }

    public function formatMorningSlackMessage(DailyReview $review): string
    {
        $review->loadMissing(['items.task.project', 'items.task.area', 'items.task.portfolio']);

        $lines = [
            'Friday Daily Briefing',
            "Today: {$review->review_date->toDateString()}",
            '',
            '```',
            ...$this->formatSlackReviewTable($review->items->sortBy('position')),
            '```',
            '',
            'Reply with commands like `done 1`, `move 2 tomorrow`, `note 3 waiting on feedback`, or `help`.',
        ];

        return implode("\n", $lines);
    }

    public function formatEveningSlackMessage(DailyReview $review): string
    {
        $review->loadMissing(['items.task.project', 'items.task.area', 'items.task.portfolio']);

        $lines = [
            'Friday Evening Check-in',
            'What did you complete, move, block, or skip today?',
            '',
            '```',
            ...$this->formatSlackReviewTable($review->items->sortBy('position'), 'snapshot_status', 'Status'),
            '```',
            '',
            'Use `done 1`, `done 2,3`, `move 4 tomorrow`, `block 5 waiting for client`, `skip 6`, or `help`.',
        ];

        return implode("\n", $lines);
    }

    private function createReview(User $user, string $type): DailyReview
    {
        $tasks = $this->collectTodayTasks($user);
        $focus = $this->selectTopFocusItems($tasks);
        $ordered = collect()
            ->merge($focus->map(fn (Task $task) => ['task' => $task, 'type' => 'focus']))
            ->merge($this->typedTasks($tasks, 'overdue'))
            ->merge($this->typedTasks($tasks, 'due_today'))
            ->merge($this->typedTasks($tasks, 'blocked'))
            ->merge($this->typedTasks($tasks, 'waiting'))
            ->merge($this->typedTasks($tasks, 'upcoming'))
            ->merge($this->typedTasks($tasks, 'no_due_date'))
            ->unique(fn (array $item) => $item['task']->id)
            ->values();

        $review = DailyReview::create([
            'user_id' => $user->id,
            'review_date' => now()->toDateString(),
            'type' => $type,
            'status' => 'pending',
            'summary' => "{$ordered->count()} open task(s) selected for {$type} review.",
        ]);

        $ordered->each(function (array $item, int $index) use ($review): void {
            /** @var Task $task */
            $task = $item['task'];

            $review->items()->create([
                'task_id' => $task->id,
                'position' => $index + 1,
                'item_type' => $item['type'],
                'snapshot_title' => $task->title,
                'snapshot_status' => $task->status,
                'snapshot_priority' => $task->priority,
                'snapshot_due_date' => $task->due_date?->toDateString(),
            ]);
        });

        return $review->load(['items.task.project', 'items.task.area', 'items.task.portfolio']);
    }

    private function typedTasks(Collection $tasks, string $type): Collection
    {
        return $tasks->get($type, collect())
            ->map(fn (Task $task) => ['task' => $task, 'type' => $type]);
    }

    private function formatSlackReviewTable(Collection $items, string $typeField = 'item_type', string $typeLabel = 'Type'): array
    {
        $rows = [
            $this->formatSlackReviewRow('No.', $typeLabel, 'Priority', 'Due Date', 'Context', 'Task'),
            $this->formatSlackReviewRow('---', '---------', '---------', '------------', '-------------------------------', '------------------------------'),
        ];

        foreach ($items as $item) {
            $rows[] = $this->formatSlackReviewRow(
                (string) $item->position,
                (string) $item->{$typeField},
                (string) $item->snapshot_priority,
                $item->snapshot_due_date?->toDateString() ?? 'no due date',
                $this->taskContext($item->task),
                (string) $item->snapshot_title,
            );
        }

        return $rows;
    }

    private function formatSlackReviewRow(string $number, string $type, string $priority, string $dueDate, string $context, string $task): string
    {
        return sprintf(
            '%-3s %-9s %-9s %-12s %-31s %-30s',
            $this->fixedWidth($number, 3),
            $this->fixedWidth($type, 9),
            $this->fixedWidth($priority, 9),
            $this->fixedWidth($dueDate, 12),
            $this->fixedWidth($context, 31),
            $this->fixedWidth($task, 30),
        );
    }

    private function fixedWidth(string $value, int $width): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';

        return strlen($value) > $width ? substr($value, 0, $width - 3).'...' : $value;
    }

    private function taskContext(?Task $task): string
    {
        if (! $task) {
            return '';
        }

        return collect([
            $task->area?->name,
            $task->portfolio?->name,
            $task->project?->name,
        ])->filter()->implode(' / ');
    }
}
