<?php

namespace App\Services\Automation;

use App\Models\AuditLog;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\TaskFlowNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class AutomationRuleService
{
    public function ensurePresets(Workspace $workspace, ?int $actorId = null): void
    {
        foreach ($this->presets() as $preset) {
            AutomationRule::query()->firstOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'trigger_type' => $preset['trigger_type'],
                    'action_type' => $preset['action_type'],
                    'name' => $preset['name'],
                ],
                [
                    'created_by' => $actorId,
                    'description' => $preset['description'],
                    'conditions' => $preset['conditions'] ?? [],
                    'action_payload' => $preset['action_payload'] ?? [],
                    'is_active' => $preset['is_active'] ?? true,
                ],
            );
        }
    }

    public function run(?Workspace $workspace = null): array
    {
        $stats = ['rules' => 0, 'executed' => 0, 'skipped' => 0, 'notifications' => 0];

        $workspaces = $workspace
            ? collect([$workspace])
            : Workspace::query()->orderBy('id')->get();

        foreach ($workspaces as $currentWorkspace) {
            $this->ensurePresets($currentWorkspace);
        }

        $rules = AutomationRule::query()
            ->with('workspace')
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->when($workspace, fn ($query) => $query->where('workspace_id', $workspace->id))
            ->orderBy('workspace_id')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            $stats['rules']++;
            $result = $this->runRule($rule);
            $stats['executed'] += $result['executed'];
            $stats['skipped'] += $result['skipped'];
            $stats['notifications'] += $result['notifications'];
            $rule->forceFill(['last_run_at' => now()])->save();
        }

        return $stats;
    }

    public function presets(): array
    {
        return [
            [
                'name' => 'Overdue task reminder',
                'description' => 'Notify assignees once per day when open tasks are overdue.',
                'trigger_type' => 'task_overdue',
                'action_type' => 'notify_assignee',
            ],
            [
                'name' => 'Due today reminder',
                'description' => 'Notify assignees once per day for tasks due today.',
                'trigger_type' => 'task_due_today',
                'action_type' => 'notify_assignee',
            ],
            [
                'name' => 'Task completed update',
                'description' => 'Notify task creators and project owners when work is completed.',
                'trigger_type' => 'task_completed',
                'action_type' => 'notify_project_owner',
            ],
            [
                'name' => 'Project at-risk alert',
                'description' => 'Alert project owners and workspace admins when a project has overdue or deadline-risk work.',
                'trigger_type' => 'project_at_risk',
                'action_type' => 'notify_workspace_admins',
            ],
            [
                'name' => 'Morning focus briefing',
                'description' => 'Create a daily notification with today focus, overdue, and missed-work counts.',
                'trigger_type' => 'daily_morning_briefing',
                'action_type' => 'create_notification',
            ],
            [
                'name' => 'Evening review reminder',
                'description' => 'Remind workspace users to review completed and missed work.',
                'trigger_type' => 'daily_evening_review',
                'action_type' => 'create_notification',
            ],
        ];
    }

    private function runRule(AutomationRule $rule): array
    {
        return match ($rule->trigger_type) {
            'task_overdue' => $this->runTaskDueRule($rule, 'overdue'),
            'task_due_today' => $this->runTaskDueRule($rule, 'due_today'),
            'task_completed' => $this->runTaskCompletedRule($rule),
            'project_at_risk' => $this->runProjectAtRiskRule($rule),
            'daily_morning_briefing' => $this->runDailyBriefingRule($rule, 'morning'),
            'daily_evening_review' => $this->runDailyBriefingRule($rule, 'evening'),
            default => ['executed' => 0, 'skipped' => 1, 'notifications' => 0],
        };
    }

    private function runTaskDueRule(AutomationRule $rule, string $type): array
    {
        $query = Task::query()
            ->with(['assignee:id,name,email', 'project:id,name,owner_id'])
            ->where('workspace_id', $rule->workspace_id)
            ->whereNotNull('assignee_id')
            ->whereNotIn('status', ['completed', 'archived']);

        $type === 'overdue'
            ? $query->whereDate('due_date', '<', now()->toDateString())
            : $query->whereDate('due_date', now()->toDateString());

        $stats = ['executed' => 0, 'skipped' => 0, 'notifications' => 0];

        $query->get()->each(function (Task $task) use ($rule, $type, &$stats): void {
            if (! $task->assignee || ! $task->assignee->canAccessWorkspace($rule->workspace_id)) {
                $stats['skipped']++;
                return;
            }

            $created = $this->recordRun($rule, $task->assignee, $task, $type);
            if (! $created) {
                $stats['skipped']++;
                return;
            }

            $label = $type === 'overdue' ? 'overdue' : 'due today';
            $task->assignee->notify(new TaskFlowNotification(
                title: 'Automation reminder',
                message: "{$task->title} is {$label}.",
                taskId: $task->id,
                projectId: $task->project_id,
                actionUrl: route('tasks.show', $task, false),
                eventType: "automation_{$type}",
            ));

            $this->audit($rule, 'automation_notification_created', $task, [
                'recipient_id' => $task->assignee->id,
                'trigger_type' => $rule->trigger_type,
            ]);

            $stats['executed']++;
            $stats['notifications']++;
        });

        return $stats;
    }

    private function runTaskCompletedRule(AutomationRule $rule): array
    {
        $stats = ['executed' => 0, 'skipped' => 0, 'notifications' => 0];

        Task::query()
            ->with(['reporter:id,name,email', 'project.owner:id,name,email'])
            ->where('workspace_id', $rule->workspace_id)
            ->where('status', 'completed')
            ->whereDate('completed_at', now()->toDateString())
            ->get()
            ->each(function (Task $task) use ($rule, &$stats): void {
                $recipients = collect([$task->reporter, $task->project?->owner])
                    ->filter()
                    ->unique('id')
                    ->filter(fn (User $user) => $user->canAccessWorkspace($rule->workspace_id));

                if ($recipients->isEmpty()) {
                    $stats['skipped']++;
                    return;
                }

                foreach ($recipients as $recipient) {
                    if (! $this->recordRun($rule, $recipient, $task, 'completed')) {
                        $stats['skipped']++;
                        continue;
                    }

                    $recipient->notify(new TaskFlowNotification(
                        title: 'Task completed',
                        message: "{$task->title} was completed.",
                        taskId: $task->id,
                        projectId: $task->project_id,
                        actionUrl: route('tasks.show', $task, false),
                        eventType: 'automation_task_completed',
                    ));
                    $stats['executed']++;
                    $stats['notifications']++;
                }
            });

        return $stats;
    }

    private function runProjectAtRiskRule(AutomationRule $rule): array
    {
        $stats = ['executed' => 0, 'skipped' => 0, 'notifications' => 0];

        Project::query()
            ->with(['owner:id,name,email', 'workspace.users:id,name,email'])
            ->withCount(['tasks as overdue_tasks_count' => fn ($query) => $query->active()->overdue()])
            ->where('workspace_id', $rule->workspace_id)
            ->where('status', '!=', 'archived')
            ->get()
            ->filter(fn (Project $project) => (int) $project->overdue_tasks_count > 0 || $this->hasDeadlineRisk($project))
            ->each(function (Project $project) use ($rule, &$stats): void {
                $admins = $project->workspace?->users()
                    ->wherePivotIn('role', ['owner', 'admin'])
                    ->get() ?? collect();

                $recipients = $admins
                    ->merge($project->owner ? collect([$project->owner]) : collect())
                    ->filter()
                    ->unique('id')
                    ->filter(fn (User $user) => $user->canAccessWorkspace($rule->workspace_id));

                foreach ($recipients as $recipient) {
                    if (! $this->recordRun($rule, $recipient, $project, 'at_risk')) {
                        $stats['skipped']++;
                        continue;
                    }

                    $recipient->notify(new TaskFlowNotification(
                        title: 'Project at risk',
                        message: "{$project->name} needs attention.",
                        projectId: $project->id,
                        actionUrl: route('projects.show', $project, false),
                        eventType: 'automation_project_at_risk',
                    ));
                    $stats['executed']++;
                    $stats['notifications']++;
                }
            });

        return $stats;
    }

    private function runDailyBriefingRule(AutomationRule $rule, string $type): array
    {
        $stats = ['executed' => 0, 'skipped' => 0, 'notifications' => 0];
        $users = $rule->workspace?->users()->get() ?? collect();

        foreach ($users as $user) {
            if (! $this->recordRun($rule, $user, $rule->workspace, $type)) {
                $stats['skipped']++;
                continue;
            }

            $summary = $this->dailySummary($user);
            $message = $type === 'morning'
                ? "Today's focus: {$summary['focus']} focus item(s), {$summary['overdue']} overdue, {$summary['missed']} missed yesterday."
                : "Evening review: {$summary['completed']} completed today, {$summary['overdue']} still overdue.";

            $user->notify(new TaskFlowNotification(
                title: $type === 'morning' ? 'Morning focus briefing' : 'Evening review reminder',
                message: $message,
                actionUrl: route('today.index', [], false),
                eventType: "automation_{$type}_briefing",
            ));

            $stats['executed']++;
            $stats['notifications']++;
        }

        return $stats;
    }

    private function recordRun(AutomationRule $rule, ?User $user, Model $target, string $suffix): bool
    {
        $run = AutomationRun::query()->firstOrCreate(
            [
                'automation_rule_id' => $rule->id,
                'run_key' => now()->toDateString(),
                'target_key' => $target::class.':'.$target->getKey().':'.$suffix,
                'user_id' => $user?->id,
            ],
            [
                'workspace_id' => $rule->workspace_id,
                'target_type' => $target::class,
                'target_id' => $target->getKey(),
                'status' => 'executed',
                'message' => $rule->name,
            ],
        );

        if (! $run->wasRecentlyCreated) {
            return false;
        }

        $this->audit($rule, 'automation_executed', $target, [
            'recipient_id' => $user?->id,
            'trigger_type' => $rule->trigger_type,
            'action_type' => $rule->action_type,
        ]);

        return true;
    }

    private function audit(AutomationRule $rule, string $action, ?Model $auditable = null, array $metadata = []): void
    {
        AuditLog::record($rule->workspace_id, null, $action, $auditable ?? $rule, [
            'automation_rule_id' => $rule->id,
            'automation_rule_name' => $rule->name,
            ...$metadata,
        ]);
    }

    private function hasDeadlineRisk(Project $project): bool
    {
        return $project->due_date !== null
            && $project->due_date->toDateString() >= now()->toDateString()
            && $project->due_date->toDateString() <= now()->addDays(7)->toDateString()
            && $project->tasks()->active()->exists();
    }

    private function dailySummary(User $user): array
    {
        $tasks = Task::query()
            ->where(function ($query) use ($user): void {
                $query->where('assignee_id', $user->id)->orWhere('reporter_id', $user->id);
            })
            ->get();

        return [
            'focus' => $tasks->filter(fn (Task $task) => $task->status !== 'completed' && $task->due_date?->toDateString() === now()->toDateString())->count(),
            'overdue' => $tasks->filter(fn (Task $task) => ! in_array($task->status, ['completed', 'archived'], true) && $task->due_date?->toDateString() < now()->toDateString())->count(),
            'missed' => $tasks->filter(fn (Task $task) => ! in_array($task->status, ['completed', 'archived'], true) && $task->due_date?->toDateString() === now()->subDay()->toDateString())->count(),
            'completed' => $tasks->filter(fn (Task $task) => $task->status === 'completed' && $task->completed_at?->toDateString() === now()->toDateString())->count(),
        ];
    }
}
