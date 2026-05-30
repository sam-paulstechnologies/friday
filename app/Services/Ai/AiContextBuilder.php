<?php

namespace App\Services\Ai;

use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AiContextBuilder
{
    public function __construct(private readonly AiSettingsService $settings)
    {
    }

    public function build(?array $scope = null, ?int $limit = null): array
    {
        $limit ??= $this->settings->maxTasksSent();

        $base = Task::query()
            ->with(['area:id,name', 'portfolio:id,name', 'project:id,name', 'assignee:id,name,email'])
            ->when($scope, fn (Builder $query) => $this->applyScope($query, $scope));

        $all = (clone $base)->get();
        $open = $all->filter(fn (Task $task): bool => $this->isOpen($task));

        $focused = (clone $base)
            ->whereNotIn('status', ['completed', 'done', 'archived'])
            ->orderByRaw("case priority when 'urgent' then 1 when 'high' then 2 when 'medium' then 3 when 'low' then 4 else 5 end")
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        $recentCompleted = (clone $base)
            ->whereIn('status', ['completed', 'done'])
            ->latest('completed_at')
            ->latest()
            ->limit(5)
            ->get();

        return [
            'generated_at' => now()->toDateTimeString(),
            'scope' => $scope,
            'summary' => $this->summary($all, $open),
            'tasks' => $focused->map(fn (Task $task): array => $this->taskRow($task))->values()->all(),
            'recent_completed' => $recentCompleted->map(fn (Task $task): array => $this->taskRow($task))->values()->all(),
            'waiting_delegated' => $focused->filter(fn (Task $task): bool => $this->isWaitingCandidate($task))->map(fn (Task $task): array => $this->taskRow($task))->values()->all(),
            'task_count_sent' => $focused->count(),
        ];
    }

    private function applyScope(Builder $query, array $scope): void
    {
        match ($scope['type'] ?? null) {
            'area' => $query->where('area_id', $scope['id']),
            'portfolio' => $query->where('portfolio_id', $scope['id']),
            'project' => $query->where('project_id', $scope['id']),
            'multi' => $query->where(function (Builder $query) use ($scope): void {
                foreach (($scope['matches'] ?? []) as $match) {
                    $query->orWhere(function (Builder $query) use ($match): void {
                        match ($match['type'] ?? null) {
                            'area' => $query->where('area_id', $match['id']),
                            'portfolio' => $query->where('portfolio_id', $match['id']),
                            'project' => $query->where('project_id', $match['id']),
                            default => null,
                        };
                    });
                }
            }),
            default => null,
        };
    }

    private function summary(Collection $all, Collection $open): array
    {
        $today = now()->toDateString();
        $week = now()->addDays(7)->toDateString();

        return [
            'open' => $open->count(),
            'overdue' => $open->filter(fn (Task $task): bool => $task->due_date !== null && $task->due_date->toDateString() < $today)->count(),
            'due_today' => $open->filter(fn (Task $task): bool => $task->due_date?->toDateString() === $today)->count(),
            'due_this_week' => $open->filter(fn (Task $task): bool => $task->due_date !== null && $task->due_date->toDateString() >= $today && $task->due_date->toDateString() <= $week)->count(),
            'urgent_high' => $open->whereIn('priority', ['urgent', 'high'])->count(),
            'no_due_date' => $open->filter(fn (Task $task): bool => $task->due_date === null)->count(),
            'waiting_delegated' => $open->filter(fn (Task $task): bool => $this->isWaitingCandidate($task))->count(),
            'recent_completed' => $all->whereIn('status', ['completed', 'done'])->count(),
        ];
    }

    private function taskRow(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'area' => $task->area?->name,
            'portfolio' => $task->portfolio?->name,
            'project' => $task->project?->name,
            'status' => $task->status,
            'priority' => $task->priority,
            'due_date' => $task->due_date?->toDateString(),
            'assignee' => $task->assignee?->name ?? $task->assignee?->email,
            'description' => $this->shortDescription($task->description),
        ];
    }

    private function shortDescription(?string $description): string
    {
        $text = preg_replace('/\s+/', ' ', strip_tags((string) $description)) ?? '';

        return Str::limit(trim($text), 160);
    }

    private function isOpen(Task $task): bool
    {
        return ! in_array($task->status, ['completed', 'done', 'archived'], true);
    }

    private function isWaitingCandidate(Task $task): bool
    {
        $text = Str::lower(implode(' ', [
            $task->title,
            $task->description,
            $task->status,
            $task->task_type,
        ]));

        return Str::of($text)->contains(['waiting', 'awaiting', 'blocked', 'delegated', 'follow up', 'client', 'support', 'tech team']);
    }
}
