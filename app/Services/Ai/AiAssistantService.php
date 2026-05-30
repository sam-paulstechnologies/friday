<?php

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use Illuminate\Support\Str;

class AiAssistantService
{
    public function __construct(
        private readonly AiAssistantConfig $config,
        private readonly AiAssistantContextService $contextService,
    ) {}

    public function respond(User $user, string $message, ?AiConversation $conversation = null): array
    {
        $conversation ??= $this->conversation($user, $message);
        $conversation->messages()->create([
            'user_id' => $user->id,
            'role' => 'user',
            'content' => $this->sanitize($message),
            'metadata' => ['provider' => $this->config->provider()],
        ]);

        if (! $this->config->enabled()) {
            return $this->recordAssistant($conversation, [
                'message' => 'Miriam Assistant is disabled. Enable AI_ASSISTANT_ENABLED and keep AI_PROVIDER=mock for local, no-cost assistant responses.',
                'conversation_id' => $conversation->id,
                'provider' => 'disabled',
                'action' => null,
            ]);
        }

        $context = $this->contextService->build($user);
        $lower = Str::lower($message);
        $response = match (true) {
            Str::contains($lower, ['focus today', 'focus on today', 'what should i focus']) => $this->dailyFocus($context),
            Str::contains($lower, ['plan my week', 'week plan']) => $this->weekPlan($context),
            Str::contains($lower, ['overloaded', 'workload']) => $this->workload($context),
            Str::contains($lower, ['summarize project', 'project summary']) => $this->projectSummary($user, $message),
            Str::contains($lower, ['create a task', 'create task', 'add a task']) => $this->taskProposal($user, $message),
            default => $this->generalSnapshot($context),
        };

        return $this->recordAssistant($conversation, [
            'message' => $this->sanitize($response['message']),
            'conversation_id' => $conversation->id,
            'provider' => $this->config->provider(),
            'action' => $response['action'] ?? null,
            'context_summary' => $context['summary'],
        ]);
    }

    private function conversation(User $user, string $message): AiConversation
    {
        return AiConversation::create([
            'user_id' => $user->id,
            'workspace_id' => collect($user->accessibleWorkspaceIds())->first(),
            'title' => Str::limit($message, 80),
        ]);
    }

    private function recordAssistant(AiConversation $conversation, array $payload): array
    {
        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'user_id' => null,
            'role' => 'assistant',
            'content' => $payload['message'],
            'metadata' => [
                'provider' => $payload['provider'],
                'action' => $payload['action'] ?? null,
                'context_summary' => $payload['context_summary'] ?? null,
            ],
        ]);

        return $payload;
    }

    private function dailyFocus(array $context): array
    {
        $tasks = collect($context['overdue_tasks'])->merge($context['today_tasks'])->unique('id')->take(5);
        $lines = $tasks->isEmpty()
            ? ['No urgent focus tasks found for today.']
            : $tasks->map(fn (array $task, int $index) => ($index + 1).'. '.$task['title'].' ['.$task['priority'].']'.($task['due_date'] ? ' due '.$task['due_date'] : ''))->all();

        return ['message' => "Today focus:\n".implode("\n", $lines)."\n\nOverdue: {$context['summary']['overdue_tasks']} | Due today: {$context['summary']['due_today']}"];
    }

    private function weekPlan(array $context): array
    {
        $grouped = collect($context['upcoming_tasks'])->groupBy(fn (array $task) => $task['due_date'] ?? 'No date');
        $lines = $grouped->isEmpty()
            ? ['No dated tasks due in the next 7 days.']
            : $grouped->map(fn ($tasks, string $date) => $date.': '.$tasks->pluck('title')->take(4)->implode(', '))->values()->all();

        return ['message' => "Next 7 days:\n".implode("\n", $lines)];
    }

    private function workload(array $context): array
    {
        return ['message' => "Workload snapshot:\nOpen tasks: {$context['summary']['open_tasks']}\nOverdue: {$context['summary']['overdue_tasks']}\nUpcoming: {$context['summary']['upcoming_tasks']}\nUnread notifications: {$context['summary']['notifications_unread']}"];
    }

    private function projectSummary(User $user, string $message): array
    {
        $needle = trim((string) preg_replace('/.*(?:summarize project|project summary)\s+/i', '', $message));
        $project = $needle !== '' ? $this->contextService->projectSummary($user, $needle) : null;

        if (! $project) {
            return ['message' => 'I could not find an accessible project matching that request.'];
        }

        return ['message' => "{$project['name']} summary:\nStatus: {$project['status']} | Health: {$project['health']}\nOpen tasks: {$project['open_tasks']} | Completed: {$project['completed_tasks']} | Overdue: {$project['overdue_tasks']}\nNext deadline: ".($project['due_date'] ?? 'No deadline')];
    }

    private function taskProposal(User $user, string $message): array
    {
        $title = trim((string) preg_replace('/.*(?:create a task to|create task to|add a task to|create a task|create task|add a task)/i', '', $message));
        $title = Str::ucfirst(trim($title, " .\t\n\r\0\x0B"));

        if ($title === '') {
            $title = 'New assistant task';
        }

        $dueDate = Str::contains(Str::lower($message), 'tomorrow') ? now()->addDay()->toDateString() : null;
        $workspaceId = collect($user->accessibleWorkspaceIds())->first(fn (int $workspaceId) => $user->canWriteWorkspace($workspaceId));

        return [
            'message' => $workspaceId
                ? "I can create this task: {$title}".($dueDate ? " due {$dueDate}" : '').'. Use the create action to confirm.'
                : 'You do not have a writable workspace for creating tasks.',
            'action' => $workspaceId ? [
                'type' => 'create_task',
                'payload' => [
                    'workspace_id' => $workspaceId,
                    'title' => $title,
                    'due_date' => $dueDate,
                    'priority' => 'medium',
                ],
            ] : null,
        ];
    }

    private function generalSnapshot(array $context): array
    {
        return ['message' => "Miriam snapshot:\nOpen: {$context['summary']['open_tasks']} | Overdue: {$context['summary']['overdue_tasks']} | Due today: {$context['summary']['due_today']}\nAsk me what to focus on today, to summarize a project, or to create a task."];
    }

    private function sanitize(string $text): string
    {
        return preg_replace('/(sk-[A-Za-z0-9_\-]+|ya29\.[A-Za-z0-9_\-\.]+|refresh_token["\']?\s*[:=]\s*["\']?[^,\s"\']+)/i', '[redacted]', $text) ?? $text;
    }
}
