<?php

namespace App\Services\Agents;

use Illuminate\Support\Str;

class CodexClaudePromptAgent
{
    public function handle(array $context): array
    {
        $idea = trim($context['idea']);
        $contextLabel = $context['context_label'] ?? 'Miriam/Friday';
        $repoPlaceholder = 'C:\\path\\to\\repo';
        $scope = 'Build only the smallest approved MVP for: '.$idea;
        $forbidden = [
            'do not edit .env',
            'do not run destructive DB commands',
            'do not deploy unless explicitly asked',
            'do not run migrate:fresh, db:wipe, reset, seed, truncate, or delete data',
            'do not call paid external APIs without explicit approval',
        ];
        $validation = [
            'php -l on changed PHP files',
            'php artisan route:list',
            'php artisan test --filter=<relevant>',
            'npm run build',
        ];

        $codexPrompt = $this->prompt('Codex', $repoPlaceholder, $contextLabel, $scope, $forbidden, $validation);
        $claudeCodePrompt = $this->prompt('Claude Code', $repoPlaceholder, $contextLabel, $scope, $forbidden, $validation);
        $claudeUiPrompt = <<<PROMPT
Review this product idea and UI plan for clarity, urgency, and demo readiness.

Context/Product: {$contextLabel}
Idea: {$idea}

Focus on:
- main screens
- first user action
- approval safety
- mobile readability
- dark mode risks
- copy clarity

Return concise findings and recommended changes only.
PROMPT;

        $markdown = <<<MD
## Codex Prompt
```text
{$codexPrompt}
```

## Claude Code Prompt
```text
{$claudeCodePrompt}
```

## Claude UI / Review Prompt
```text
{$claudeUiPrompt}
```

## Repo Path Placeholder
{$repoPlaceholder}

## Files To Inspect
- routes/web.php
- app/Services
- app/Http/Controllers
- resources/js/Pages
- tests/Feature

## Constraints
- {$forbidden[0]}
- {$forbidden[1]}
- {$forbidden[2]}
- {$forbidden[3]}
- {$forbidden[4]}

## Validation Commands
- {$validation[0]}
- {$validation[1]}
- {$validation[2]}
- {$validation[3]}

## Expected Final Report Format
Files changed, routes changed, migrations, tests, validation result, risks.
MD;

        return [
            'agent_key' => 'codex-claude-prompt',
            'agent_name' => 'Codex / Claude Prompt Agent',
            'title' => 'Execution prompts for Codex and Claude',
            'category' => 'codex_claude_prompt',
            'priority' => 'high',
            'due_label' => 'no_due_date',
            'generated_task_title' => 'Review generated Codex and Claude prompts',
            'suggested_next_action' => 'Copy the prompt only after approving the scope and forbidden commands.',
            'payload' => [
                'codex_prompt' => $codexPrompt,
                'claude_code_prompt' => $claudeCodePrompt,
                'claude_ui_review_prompt' => $claudeUiPrompt,
                'repo_path_placeholder' => $repoPlaceholder,
                'files_to_inspect' => ['routes/web.php', 'app/Services', 'app/Http/Controllers', 'resources/js/Pages', 'tests/Feature'],
                'scope' => $scope,
                'constraints' => $forbidden,
                'forbidden_commands' => $forbidden,
                'validation_commands' => $validation,
                'expected_final_report_format' => 'Files changed, routes changed, migrations, tests, validation result, risks.',
                'markdown' => $markdown,
            ],
        ];
    }

    private function prompt(string $tool, string $repoPlaceholder, string $contextLabel, string $scope, array $forbidden, array $validation): string
    {
        $forbiddenText = collect($forbidden)->map(fn (string $item) => '- '.$item)->implode("\n");
        $validationText = collect($validation)->map(fn (string $item) => '- '.$item)->implode("\n");

        return <<<PROMPT
You are working in {$repoPlaceholder}.

Context/Product: {$contextLabel}
Tool: {$tool}
Scope: {$scope}

Constraints:
{$forbiddenText}

Validation:
{$validationText}

Final report:
- Files changed
- Routes changed
- Migrations
- Tests
- Validation result
- Remaining risks
PROMPT;
    }
}
