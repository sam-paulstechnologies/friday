<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class TaskCaptureAgent
{
    public const SLUG = 'task-capture';

    public function ensureRegistered(): Agent
    {
        return Agent::query()->firstOrCreate(
            ['slug' => self::SLUG],
            [
                'name' => 'Task Capture Agent',
                'description' => 'Turns messy notes into structured, reviewable task proposals using local rule-based classification.',
                'status' => 'active',
                'metadata' => [
                    'mode' => 'rule_based',
                    'creates_tasks_automatically' => false,
                ],
            ],
        );
    }

    public function run(User $user, string $input): AgentRun
    {
        $agent = $this->ensureRegistered();
        $workspaceId = collect($user->accessibleWorkspaceIds())->first();

        return DB::transaction(function () use ($agent, $user, $workspaceId, $input): AgentRun {
            $run = AgentRun::create([
                'agent_id' => $agent->id,
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'original_input' => trim($input),
                'status' => AgentRun::STATUS_RUNNING,
                'started_at' => now(),
            ]);

            $this->log($run, 'info', 'Task Capture Agent run started.');

            try {
                $this->log($run, 'info', 'Input received.', [
                    'characters' => mb_strlen($input),
                    'words' => str_word_count($input),
                ]);

                $classification = $this->classify($input);

                $this->log($run, 'info', 'Rule classification completed.', [
                    'categories' => $classification['categories'],
                    'detected_projects' => $classification['detected_projects'],
                    'priority' => $classification['priority'],
                    'due_label' => $classification['due_label'],
                ]);

                foreach ($classification['outputs'] as $output) {
                    $run->outputs()->create([
                        'category' => $output['category'],
                        'detected_projects' => $classification['detected_projects'],
                        'priority' => $classification['priority'],
                        'due_label' => $classification['due_label'],
                        'generated_task_title' => $output['generated_task_title'],
                        'suggested_next_action' => $output['suggested_next_action'],
                        'payload' => [
                            'source' => 'task_capture_agent',
                            'all_categories' => $classification['categories'],
                            'confidence' => $output['confidence'],
                        ],
                    ]);

                    $this->log($run, 'info', "Created {$output['category']} task proposal.", [
                        'title' => $output['generated_task_title'],
                    ]);
                }

                $run->update([
                    'status' => AgentRun::STATUS_COMPLETED,
                    'result' => $classification,
                    'completed_at' => now(),
                ]);

                $this->log($run, 'info', 'Task Capture Agent run completed.');
            } catch (Throwable $exception) {
                $run->update([
                    'status' => AgentRun::STATUS_FAILED,
                    'error_message' => $exception->getMessage(),
                    'completed_at' => now(),
                ]);

                $this->log($run, 'error', 'Task Capture Agent run failed.', [
                    'error' => $exception->getMessage(),
                ]);
            }

            return $run->fresh(['agent', 'outputs', 'logs']);
        });
    }

    public function classify(string $input): array
    {
        $summary = $this->summary($input);
        $normalized = $this->normalize($input);
        $projects = $this->detectProjects($normalized);
        $categories = $this->detectCategories($normalized, $projects);
        $priority = $this->detectPriority($normalized);
        $dueLabel = $this->detectDueLabel($normalized);

        $outputs = collect($categories)
            ->map(fn (string $category) => [
                'category' => $category,
                'generated_task_title' => $this->generatedTaskTitle($category, $summary, $projects),
                'suggested_next_action' => $this->suggestedNextAction($category, $summary, $projects),
                'confidence' => $category === 'general_task' ? 'low' : 'medium',
            ])
            ->values()
            ->all();

        return [
            'original_input' => trim($input),
            'categories' => $categories,
            'detected_category' => $categories[0],
            'detected_projects' => $projects,
            'priority' => $priority,
            'due_label' => $dueLabel,
            'generated_task_title' => $outputs[0]['generated_task_title'],
            'suggested_next_action' => $this->combinedNextAction($categories, $summary, $projects),
            'outputs' => $outputs,
        ];
    }

    private function detectProjects(string $normalized): array
    {
        $projectAliases = [
            'SayaraForce' => ['sayaraforce', 'sayara force'],
            'ChurchForce' => ['churchforce', 'church force'],
            'CFFA' => ['cffa', 'cffa academy'],
            'Miriam' => ['miriam', 'friday'],
            'Smart Matrix' => ['smart matrix'],
            'Mecline' => ['mecline'],
            'Paul’s Technologies' => ['paul’s technologies', "paul's technologies", 'pauls technologies', 'the pauls technologies'],
            'Judah' => ['judah'],
            'Nikhila' => ['nikhila'],
        ];

        return collect($projectAliases)
            ->filter(fn (array $aliases) => collect($aliases)->contains(fn (string $alias) => str_contains($normalized, $this->normalize($alias))))
            ->keys()
            ->values()
            ->all();
    }

    private function detectCategories(string $normalized, array $projects): array
    {
        $categories = collect();

        if ($this->containsAny($normalized, ['code', 'coding', 'ui', 'bug', 'issue', 'production', 'deploy', 'fix', 'backend', 'frontend', 'app', 'feature', 'server', 'build', 'manager ui'])) {
            $categories->push('coding');
        }

        if ($this->containsAny($normalized, ['client', 'follow up', 'followup', 'reply', 'message', 'email', 'send', 'proposal']) || in_array('Mecline', $projects, true)) {
            $categories->push('client_followup');
        }

        if ($this->containsAny($normalized, ['work survival', 'job risk', 'boss', 'office', 'salary', 'hr issue'])) {
            $categories->push('work_survival');
        }

        if ($this->containsAny($normalized, ['health', 'medicine', 'medication', 'doctor', 'clinic', 'exercise', 'workout'])) {
            $categories->push('health');
        }

        if ($this->containsAny($normalized, ['family', 'school', 'class', 'swimming', 'home']) || array_intersect($projects, ['Judah', 'Nikhila'])) {
            $categories->push('family');
        }

        if ($this->containsAny($normalized, ['finance', 'payment', 'invoice', 'bill', 'bank', 'budget', 'money'])) {
            $categories->push('finance');
        }

        if ($this->containsAny($normalized, ['content', 'post', 'blog', 'video', 'script', 'article', 'newsletter'])) {
            $categories->push('content');
        }

        if ($this->containsAny($normalized, ['remind', 'reminder', 'remember', 'schedule'])) {
            $categories->push('reminder');
        }

        if ($this->containsAny($normalized, ['decision', 'decide', 'choose', 'approve', 'approval', 'confirm'])) {
            $categories->push('decision');
        }

        return $categories
            ->whenEmpty(fn (Collection $items) => $items->push('general_task'))
            ->unique()
            ->values()
            ->all();
    }

    private function detectPriority(string $normalized): string
    {
        if ($this->containsAny($normalized, ['urgent', 'today', 'now', 'blocked', 'issue', 'client', 'follow up', 'followup', 'payment', 'deadline'])) {
            return 'high';
        }

        if ($this->containsAny($normalized, ['tomorrow', 'this week', 'prepare', 'review'])) {
            return 'medium';
        }

        return 'low';
    }

    private function detectDueLabel(string $normalized): string
    {
        if (str_contains($normalized, 'today')) {
            return 'today';
        }

        if (str_contains($normalized, 'tomorrow')) {
            return 'tomorrow';
        }

        if (str_contains($normalized, 'this week')) {
            return 'this_week';
        }

        return 'no_due_date';
    }

    private function generatedTaskTitle(string $category, string $summary, array $projects): string
    {
        $prefix = match ($category) {
            'coding' => 'Coding',
            'client_followup' => 'Follow up',
            'work_survival' => 'Work survival',
            'health' => 'Health',
            'family' => 'Family',
            'finance' => 'Finance',
            'content' => 'Content',
            'reminder' => 'Reminder',
            'decision' => 'Decision',
            default => 'Task',
        };

        $projectText = $projects === [] ? null : implode(' / ', $projects);
        $title = $projectText
            ? "{$prefix}: {$projectText} - {$summary}"
            : "{$prefix}: {$summary}";

        return Str::limit($title, 140, '');
    }

    private function suggestedNextAction(string $category, string $summary, array $projects): string
    {
        $projectText = $projects === [] ? 'the captured item' : implode(' and ', $projects);

        return match ($category) {
            'coding' => "Create a coding task for {$projectText}, capture the current state, and define the next fix or build step.",
            'client_followup' => "Prepare a follow-up message for {$projectText} and decide whether it needs to be sent today.",
            'work_survival' => 'Create a work survival task with the immediate action, owner, and next check-in point.',
            'health' => 'Create a health task and decide whether it needs a reminder or same-day action.',
            'family' => "Create a family task to handle: {$summary}.",
            'finance' => 'Create a finance task, confirm the amount or deadline, and decide the next payment/admin step.',
            'content' => 'Create a content task with the format, audience, and next draft step.',
            'reminder' => 'Create a reminder with the needed date or follow-up trigger.',
            'decision' => 'Create a decision item with options, deadline, and the smallest next clarifying question.',
            default => "Create a general task from: {$summary}.",
        };
    }

    private function combinedNextAction(array $categories, string $summary, array $projects): string
    {
        if (in_array('coding', $categories, true) && in_array('client_followup', $categories, true)) {
            $projectText = $projects === [] ? 'the project/client' : implode(' and ', $projects);

            return "Create a coding task for {$projectText} and prepare the related follow-up message.";
        }

        return $this->suggestedNextAction($categories[0], $summary, $projects);
    }

    private function summary(string $input): string
    {
        $summary = trim(preg_replace('/\s+/', ' ', $input) ?? '');

        if ($summary === '') {
            return 'Untitled capture';
        }

        return Str::limit($summary, 120, '');
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $this->normalize($needle))) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        return str($value)
            ->lower()
            ->replace(['-', '_', '’'], [' ', ' ', "'"])
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }

    private function log(AgentRun $run, string $level, string $message, array $context = []): void
    {
        $run->logs()->create([
            'level' => $level,
            'message' => $message,
            'context' => $context === [] ? null : $context,
            'occurred_at' => now(),
        ]);
    }
}
