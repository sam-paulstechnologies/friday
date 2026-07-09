<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Models\AgentOutput;
use App\Models\AgentRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class AgentOrchestratorService
{
    public const ORCHESTRATOR = 'agent-orchestrator';

    private const AGENTS = [
        self::ORCHESTRATOR => [
            'name' => 'Agent Orchestrator',
            'description' => 'Coordinates the controlled Miriam Agent OS pipeline from rough idea to reviewable outputs.',
            'category' => 'orchestration',
        ],
        'research' => [
            'name' => 'Research Agent',
            'description' => 'Creates a first-pass research brief using rule-based analysis and provider placeholders.',
            'category' => 'research',
        ],
        'idea-validation' => [
            'name' => 'Idea Validation Agent',
            'description' => 'Scores whether the idea is worth building now and recommends build, defer, or reject.',
            'category' => 'validation',
        ],
        'prd-md' => [
            'name' => 'PRD / MD Agent',
            'description' => 'Turns a validated idea into copyable markdown product documentation.',
            'category' => 'documentation',
        ],
        'resource-manager' => [
            'name' => 'Resource / Token Manager Agent',
            'description' => 'Recommends the right tool, effort level, risk, and approval gate.',
            'category' => 'routing',
        ],
        'codex-claude-prompt' => [
            'name' => 'Codex / Claude Prompt Agent',
            'description' => 'Generates safe execution prompts for Codex, Claude Code, and Claude review.',
            'category' => 'prompting',
        ],
        'test-plan' => [
            'name' => 'Test Plan Agent',
            'description' => 'Creates validation, route, migration, build, smoke, deploy, and rollback checks.',
            'category' => 'quality',
        ],
        'ui-ux-marketing' => [
            'name' => 'UI/UX + Marketing Offer Agent',
            'description' => 'Reviews the experience and creates a first market offer for validation.',
            'category' => 'go_to_market',
        ],
    ];

    public function __construct(
        private readonly ResearchAgent $researchAgent,
        private readonly IdeaValidationAgent $ideaValidationAgent,
        private readonly PrdMdAgent $prdMdAgent,
        private readonly ResourceManagerAgent $resourceManagerAgent,
        private readonly CodexClaudePromptAgent $codexClaudePromptAgent,
        private readonly TestPlanAgent $testPlanAgent,
        private readonly UiUxMarketingAgent $uiUxMarketingAgent,
    ) {}

    public function ensureAgents(): array
    {
        return collect(self::AGENTS)
            ->map(function (array $definition, string $slug): Agent {
                return Agent::query()->firstOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => $definition['name'],
                        'description' => $definition['description'],
                        'status' => 'active',
                        'metadata' => [
                            'agent_os' => true,
                            'category' => $definition['category'],
                            'mode' => 'rule_based',
                            'external_api_required' => false,
                            'creates_actions_automatically' => false,
                        ],
                    ],
                );
            })
            ->all();
    }

    public function agentOptions(): array
    {
        $this->ensureAgents();

        return collect(self::AGENTS)
            ->map(fn (array $definition, string $slug) => [
                'key' => $slug,
                'name' => $definition['name'],
                'description' => $definition['description'],
                'category' => $definition['category'],
            ])
            ->values()
            ->all();
    }

    public function contexts(): array
    {
        return [
            'SayaraForce',
            'ChurchForce',
            'CFFA Academy',
            'Miriam/Friday',
            "Paul's Technologies",
            'Photography/Boutique',
            'Work survival',
            'Personal/Health',
            'Finance/Life admin',
        ];
    }

    public function run(User $user, array $data): AgentRun
    {
        return $this->runPipeline($user, [
            ...$data,
            'mode' => $data['mode'] ?? 'full_pipeline',
        ]);
    }

    public function runSelectedAgent(User $user, array $data): AgentRun
    {
        return $this->runPipeline($user, [
            ...$data,
            'mode' => 'selected_agent',
        ]);
    }

    public function runPipeline(User $user, array $data): AgentRun
    {
        $agents = $this->ensureAgents();
        $idea = trim($data['idea']);
        $contextLabel = $data['context_label'] ?? 'Miriam/Friday';
        $mode = $data['mode'] ?? 'full_pipeline';
        $selectedAgent = $data['selected_agent'] ?? null;
        $forceContinue = (bool) ($data['force_continue'] ?? false);
        $workspaceId = collect($user->accessibleWorkspaceIds())->first();

        return DB::transaction(function () use ($agents, $user, $workspaceId, $idea, $contextLabel, $mode, $selectedAgent, $forceContinue): AgentRun {
            $parentRun = AgentRun::create([
                'agent_id' => $agents[self::ORCHESTRATOR]->id,
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'context_label' => $contextLabel,
                'mode' => $mode,
                'selected_agent' => $selectedAgent,
                'original_input' => $idea,
                'status' => AgentRun::STATUS_RUNNING,
                'started_at' => now(),
            ]);

            $this->log($parentRun, 'info', 'Agent OS pipeline started.', [
                'mode' => $mode,
                'context' => $contextLabel,
                'selected_agent' => $selectedAgent,
            ]);

            $outputs = [];
            $childRuns = [];
            $validation = null;
            $stoppedReason = null;
            $steps = $this->stepsFor($mode, $selectedAgent);

            try {
                foreach ($steps as $agentKey) {
                    if ($agentKey === 'prd-md' && $this->shouldSkipPrd($validation, $forceContinue)) {
                        $stoppedReason = 'PRD skipped because validation was '.$validation['verdict'].'.';
                        $this->log($parentRun, 'warning', $stoppedReason, [
                            'force_continue' => $forceContinue,
                        ]);
                        continue;
                    }

                    $run = $agentKey === self::ORCHESTRATOR
                        ? $parentRun
                        : $this->createChildRun($agents[$agentKey], $parentRun, $user, $workspaceId, $idea, $contextLabel, $mode, $selectedAgent);

                    if ($run->isNot($parentRun)) {
                        $childRuns[] = $run->id;
                    }

                    $this->log($run, 'info', self::AGENTS[$agentKey]['name'].' started.');

                    $result = $this->executeAgent($agentKey, [
                        'idea' => $idea,
                        'context_label' => $contextLabel,
                        'mode' => $mode,
                        'selected_agent' => $selectedAgent,
                        'validation' => $validation,
                        'previous_outputs' => $outputs,
                    ], $steps, $validation, $forceContinue);

                    if ($agentKey === 'idea-validation') {
                        $validation = $result['payload'];
                    }

                    $output = $this->createOutput($run, $result, $contextLabel);
                    $outputs[] = $this->outputSummary($output);

                    $run->update([
                        'status' => $agentKey === self::ORCHESTRATOR ? AgentRun::STATUS_RUNNING : AgentRun::STATUS_COMPLETED,
                        'result' => $result['payload'],
                        'completed_at' => $agentKey === self::ORCHESTRATOR ? null : now(),
                    ]);

                    $this->log($run, 'info', self::AGENTS[$agentKey]['name'].' completed.', [
                        'output_id' => $output->id,
                        'status' => $output->status,
                    ]);

                    if ($agentKey === 'idea-validation' && $validation['verdict'] === 'reject' && ! $forceContinue) {
                        $stoppedReason = 'Pipeline stopped after rejected idea validation verdict.';
                        $this->log($parentRun, 'warning', $stoppedReason);
                        break;
                    }
                }

                $parentRun->update([
                    'status' => AgentRun::STATUS_NEEDS_REVIEW,
                    'result' => [
                        'summary' => 'Agent OS created '.count($outputs).' reviewable output'.(count($outputs) === 1 ? '' : 's').'.',
                        'context_label' => $contextLabel,
                        'mode' => $mode,
                        'selected_agent' => $selectedAgent,
                        'force_continue' => $forceContinue,
                        'outputs' => $outputs,
                        'child_run_ids' => $childRuns,
                        'stopped_reason' => $stoppedReason,
                        'next_recommended_action' => $this->nextRecommendedAction($outputs, $stoppedReason),
                    ],
                    'completed_at' => now(),
                ]);

                $this->log($parentRun, 'info', 'Agent OS pipeline completed.', [
                    'outputs' => count($outputs),
                    'stopped_reason' => $stoppedReason,
                ]);
            } catch (Throwable $exception) {
                $parentRun->update([
                    'status' => AgentRun::STATUS_FAILED,
                    'error_message' => $exception->getMessage(),
                    'completed_at' => now(),
                ]);

                $this->log($parentRun, 'error', 'Agent OS pipeline failed.', [
                    'error' => $exception->getMessage(),
                ]);
            }

            return $parentRun->fresh(['agent', 'outputs', 'logs', 'childRuns.agent', 'childRuns.outputs', 'childRuns.logs']);
        });
    }

    private function stepsFor(string $mode, ?string $selectedAgent): array
    {
        if ($mode === 'selected_agent') {
            $agentKey = $selectedAgent ?: 'research';

            return array_key_exists($agentKey, self::AGENTS) ? [$agentKey] : ['research'];
        }

        return [
            self::ORCHESTRATOR,
            'research',
            'idea-validation',
            'prd-md',
            'resource-manager',
            'codex-claude-prompt',
            'test-plan',
            'ui-ux-marketing',
        ];
    }

    private function executeAgent(string $agentKey, array $context, array $steps, ?array $validation, bool $forceContinue): array
    {
        return match ($agentKey) {
            self::ORCHESTRATOR => $this->orchestratorOutput($context, $steps, $forceContinue),
            'research' => $this->researchAgent->handle($context),
            'idea-validation' => $this->ideaValidationAgent->handle($context),
            'prd-md' => $this->prdMdAgent->handle($context),
            'resource-manager' => $this->resourceManagerAgent->handle($context),
            'codex-claude-prompt' => $this->codexClaudePromptAgent->handle($context),
            'test-plan' => $this->testPlanAgent->handle($context),
            'ui-ux-marketing' => $this->uiUxMarketingAgent->handle($context),
            default => $this->researchAgent->handle($context),
        };
    }

    private function orchestratorOutput(array $context, array $steps, bool $forceContinue): array
    {
        $agentNames = collect($steps)
            ->map(fn (string $key) => self::AGENTS[$key]['name'])
            ->implode(', ');
        $idea = trim($context['idea']);
        $contextLabel = $context['context_label'];
        $markdown = <<<MD
## Pipeline Run Summary
Miriam will process one rough idea through a controlled, review-only agent pipeline.

## Context / Product
{$contextLabel}

## Selected Agents
{$agentNames}

## Guardrails
- Outputs are proposals only.
- No code edits, deploys, marketing sends, or paid API calls are run automatically.
- Anything important remains in review before action.

## Next Recommended Action
Review the generated outputs, approve the useful ones, reject the noisy ones, then copy prompts only when ready.
MD;

        return [
            'agent_key' => self::ORCHESTRATOR,
            'agent_name' => 'Agent Orchestrator',
            'title' => 'Pipeline summary',
            'category' => 'agent_orchestrator',
            'priority' => 'high',
            'due_label' => 'today',
            'generated_task_title' => 'Review Agent OS pipeline outputs',
            'suggested_next_action' => 'Review outputs in order and approve only the pieces you want to act on.',
            'payload' => [
                'pipeline_run_summary' => 'Controlled review-only run for: '.Str::limit($idea, 120),
                'statuses' => collect($steps)->mapWithKeys(fn (string $key) => [$key => 'pending'])->all(),
                'next_recommended_action' => 'Review outputs, approve useful items, reject noise, then copy prompts manually.',
                'approval_items' => collect($steps)->map(fn (string $key) => self::AGENTS[$key]['name'])->values()->all(),
                'force_continue' => $forceContinue,
                'markdown' => $markdown,
            ],
        ];
    }

    private function createChildRun(Agent $agent, AgentRun $parentRun, User $user, ?int $workspaceId, string $idea, string $contextLabel, string $mode, ?string $selectedAgent): AgentRun
    {
        return AgentRun::create([
            'agent_id' => $agent->id,
            'parent_run_id' => $parentRun->id,
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'context_label' => $contextLabel,
            'mode' => $mode,
            'selected_agent' => $selectedAgent,
            'original_input' => $idea,
            'status' => AgentRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    private function createOutput(AgentRun $run, array $result, string $contextLabel): AgentOutput
    {
        return $run->outputs()->create([
            'agent_key' => $result['agent_key'],
            'agent_name' => $result['agent_name'],
            'context_label' => $contextLabel,
            'category' => $result['category'],
            'title' => $result['title'],
            'status' => AgentOutput::STATUS_NEEDS_REVIEW,
            'detected_projects' => $this->detectedProjects($run->original_input, $contextLabel),
            'priority' => $result['priority'],
            'due_label' => $result['due_label'],
            'generated_task_title' => $result['generated_task_title'],
            'suggested_next_action' => $result['suggested_next_action'],
            'payload' => [
                ...$result['payload'],
                'surface_in_today' => true,
                'review_required' => true,
            ],
            'sent_to_today_at' => now(),
        ]);
    }

    private function shouldSkipPrd(?array $validation, bool $forceContinue): bool
    {
        if ($forceContinue || $validation === null) {
            return false;
        }

        return in_array($validation['verdict'] ?? null, ['reject', 'needs_research'], true);
    }

    private function detectedProjects(string $idea, string $contextLabel): array
    {
        $text = Str::lower($idea.' '.$contextLabel);
        $projects = [
            'SayaraForce' => ['sayaraforce', 'sayara'],
            'ChurchForce' => ['churchforce', 'church force'],
            'CFFA Academy' => ['cffa', 'academy'],
            'Miriam/Friday' => ['miriam', 'friday'],
            'Smart Matrix' => ['smart matrix'],
            'Mecline' => ['mecline'],
            "Paul's Technologies" => ['paul', 'technologies'],
            'Judah' => ['judah'],
            'Nikhila' => ['nikhila'],
        ];

        return collect($projects)
            ->filter(fn (array $needles) => collect($needles)->contains(fn (string $needle) => str_contains($text, $needle)))
            ->keys()
            ->values()
            ->all();
    }

    private function outputSummary(AgentOutput $output): array
    {
        return [
            'id' => $output->id,
            'agent_key' => $output->agent_key,
            'agent_name' => $output->agent_name,
            'title' => $output->title,
            'status' => $output->status,
            'category' => $output->category,
        ];
    }

    private function nextRecommendedAction(array $outputs, ?string $stoppedReason): string
    {
        if ($stoppedReason) {
            return $stoppedReason.' Review the validation output before continuing.';
        }

        return count($outputs) > 0
            ? 'Review the waiting outputs, approve useful items, and copy the generated prompt only after approval.'
            : 'No outputs were generated. Review logs and rerun with a clearer idea.';
    }

    private function log(AgentRun $run, string $level, string $message, array $context = []): void
    {
        $run->logs()->create([
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }
}
