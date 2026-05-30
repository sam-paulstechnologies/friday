<?php

namespace App\Services\Ai;

use App\Models\AutomationRule;
use App\Models\CalendarConnection;
use App\Models\Goal;
use App\Models\Portfolio;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AiAssistantContextService
{
    public function build(User $user): array
    {
        $workspaceIds = $user->accessibleWorkspaceIds();
        $taskBase = $this->taskBase($user, $workspaceIds);
        $open = (clone $taskBase)->whereNotIn('status', ['completed', 'archived']);
        $today = CarbonImmutable::today();

        return [
            'generated_at' => now()->toDateTimeString(),
            'user' => $user->only(['id', 'name']),
            'workspace_ids' => $workspaceIds,
            'summary' => [
                'open_tasks' => (clone $open)->count(),
                'overdue_tasks' => (clone $open)->whereDate('due_date', '<', $today->toDateString())->count(),
                'due_today' => (clone $open)->whereDate('due_date', $today->toDateString())->count(),
                'upcoming_tasks' => (clone $open)->whereBetween('due_date', [$today->addDay()->toDateString(), $today->addDays(7)->toDateString()])->count(),
                'notifications_unread' => $user->unreadNotifications()->count(),
                'active_automations' => $this->workspaceScoped(AutomationRule::query(), $workspaceIds)->where('is_active', true)->whereNull('archived_at')->count(),
                'calendar_connected' => CalendarConnection::query()->where('user_id', $user->id)->where('provider', 'google')->where('is_active', true)->exists(),
            ],
            'today_tasks' => $this->taskRows((clone $open)->where(function (Builder $query) use ($today): void {
                $query->whereDate('due_date', $today->toDateString())
                    ->orWhereDate('start_date', $today->toDateString());
            })->limit(8)->get()),
            'overdue_tasks' => $this->taskRows((clone $open)->whereDate('due_date', '<', $today->toDateString())->orderBy('due_date')->limit(8)->get()),
            'upcoming_tasks' => $this->taskRows((clone $open)->whereBetween('due_date', [$today->addDay()->toDateString(), $today->addDays(7)->toDateString()])->orderBy('due_date')->limit(12)->get()),
            'projects' => $this->projectRows($this->workspaceScoped(Project::query(), $workspaceIds)->where('status', '!=', 'archived')->withCount([
                'tasks as open_tasks_count' => fn (Builder $query) => $query->whereNotIn('status', ['completed', 'archived']),
                'tasks as completed_tasks_count' => fn (Builder $query) => $query->where('status', 'completed'),
            ])->orderByRaw('due_date is null')->orderBy('due_date')->limit(12)->get()),
            'goals' => $this->workspaceScoped(Goal::query(), $workspaceIds)->whereNotIn('status', ['completed', 'archived'])->orderBy('due_date')->limit(8)->get(['id', 'title', 'status', 'due_date'])->map(fn (Goal $goal) => [
                'id' => $goal->id,
                'title' => $goal->title,
                'status' => $goal->status,
                'due_date' => $goal->due_date?->toDateString(),
            ])->values()->all(),
            'portfolios' => $this->workspaceScoped(Portfolio::query(), $workspaceIds)->where('status', '!=', 'archived')->withCount(['tasks', 'projects'])->limit(8)->get()->map(fn (Portfolio $portfolio) => [
                'id' => $portfolio->id,
                'name' => $portfolio->name,
                'status' => $portfolio->status,
                'tasks_count' => $portfolio->tasks_count,
                'projects_count' => $portfolio->projects_count,
            ])->values()->all(),
        ];
    }

    public function projectSummary(User $user, string $needle): ?array
    {
        $workspaceIds = $user->accessibleWorkspaceIds();
        $project = $this->workspaceScoped(Project::query(), $workspaceIds)
            ->where(function (Builder $query) use ($needle): void {
                $query->where('name', 'like', "%{$needle}%")
                    ->orWhere('slug', 'like', "%{$needle}%");
            })
            ->with(['owner:id,name', 'workspace:id,name'])
            ->withCount([
                'tasks as open_tasks_count' => fn (Builder $query) => $query->whereNotIn('status', ['completed', 'archived']),
                'tasks as completed_tasks_count' => fn (Builder $query) => $query->where('status', 'completed'),
                'tasks as overdue_tasks_count' => fn (Builder $query) => $query->whereNotIn('status', ['completed', 'archived'])->whereDate('due_date', '<', now()->toDateString()),
            ])
            ->first();

        if (! $project) {
            return null;
        }

        return [
            'id' => $project->id,
            'name' => $project->name,
            'status' => $project->status,
            'health' => $project->health,
            'due_date' => $project->due_date?->toDateString(),
            'owner' => $project->owner?->name,
            'workspace' => $project->workspace?->name,
            'open_tasks' => $project->open_tasks_count,
            'completed_tasks' => $project->completed_tasks_count,
            'overdue_tasks' => $project->overdue_tasks_count,
        ];
    }

    private function taskBase(User $user, array $workspaceIds): Builder
    {
        return Task::query()
            ->with(['project:id,name', 'workspace:id,name', 'assignee:id,name'])
            ->when(
                $workspaceIds !== [],
                fn (Builder $query) => $query->whereIn('workspace_id', $workspaceIds),
                fn (Builder $query) => $query->whereRaw('1 = 0'),
            )
            ->where(function (Builder $query) use ($user): void {
                $query->where('assignee_id', $user->id)
                    ->orWhere('reporter_id', $user->id)
                    ->orWhereHas('project.members', fn (Builder $memberQuery) => $memberQuery->whereKey($user->id));
            })
            ->orderByRaw("case priority when 'urgent' then 1 when 'high' then 2 when 'medium' then 3 when 'low' then 4 else 5 end")
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderBy('id');
    }

    private function workspaceScoped(Builder $query, array $workspaceIds): Builder
    {
        return $query->when(
            $workspaceIds !== [],
            fn (Builder $query) => $query->whereIn('workspace_id', $workspaceIds),
            fn (Builder $query) => $query->whereRaw('1 = 0'),
        );
    }

    private function taskRows(Collection $tasks): array
    {
        return $tasks->map(fn (Task $task) => [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'due_date' => $task->due_date?->toDateString(),
            'project' => $task->project?->name,
            'workspace' => $task->workspace?->name,
            'assignee' => $task->assignee?->name,
        ])->values()->all();
    }

    private function projectRows(Collection $projects): array
    {
        return $projects->map(fn (Project $project) => [
            'id' => $project->id,
            'name' => $project->name,
            'status' => $project->status,
            'health' => $project->health,
            'due_date' => $project->due_date?->toDateString(),
            'open_tasks' => $project->open_tasks_count,
            'completed_tasks' => $project->completed_tasks_count,
        ])->values()->all();
    }
}
