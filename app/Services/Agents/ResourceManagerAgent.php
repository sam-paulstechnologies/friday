<?php

namespace App\Services\Agents;

use Illuminate\Support\Str;

class ResourceManagerAgent
{
    public function handle(array $context): array
    {
        $idea = trim($context['idea']);
        $text = Str::lower($idea);
        $recommendedTool = Str::contains($text, ['code', 'build', 'laravel', 'repo', 'ui', 'agent'])
            ? 'Codex'
            : 'Miriam rule-based';
        $effort = Str::contains($text, ['integration', 'automation', 'pipeline', 'agent']) ? 'large' : 'medium';
        $risk = $effort === 'large' ? 'medium' : 'low';
        $runNow = $risk === 'low' ? 'run now after approval' : 'run later after review';
        $warning = $effort === 'large' ? 'High effort: get approval before spending AI/tool time.' : 'No paid-tool usage in MVP.';

        $markdown = <<<MD
## Recommended Tool
{$recommendedTool}

## Why This Tool
Use Codex or Claude Code for repo-aware implementation. Use Miriam rule-based routing for simple classification and review queues.

## Estimated Effort
{$effort}

## Risk Level
{$risk}

## Should Run Now Or Later
{$runNow}

## Approval Required
Yes. Never auto-run paid tools in this MVP.

## Cost / Quota Warning
{$warning}
MD;

        return [
            'agent_key' => 'resource-manager',
            'agent_name' => 'Resource / Token Manager Agent',
            'title' => 'Tool recommendation: '.$recommendedTool,
            'category' => 'resource_manager',
            'priority' => $risk === 'medium' ? 'high' : 'medium',
            'due_label' => 'no_due_date',
            'generated_task_title' => 'Approve tool choice for the next step',
            'suggested_next_action' => 'Approve the recommended tool only if the scope and validation commands are clear.',
            'payload' => [
                'recommended_tool' => $recommendedTool,
                'why_this_tool' => 'Codex/Claude Code for codebase work; Claude/ChatGPT for research, PRD, UI critique, and copy; Miriam rule-based for routing.',
                'estimated_effort' => $effort,
                'risk_level' => $risk,
                'should_run_now_or_later' => $runNow,
                'approval_required' => true,
                'cost_quota_warning' => $warning,
                'markdown' => $markdown,
            ],
        ];
    }
}
