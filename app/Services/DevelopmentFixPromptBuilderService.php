<?php

namespace App\Services;

use App\Models\MiriamDevelopmentFailure;
use Illuminate\Support\Str;

class DevelopmentFixPromptBuilderService
{
    public function build(MiriamDevelopmentFailure $failure): string
    {
        $phaseRun = $failure->phaseRun;
        $phase = $phaseRun?->phase;
        $changedFiles = $phaseRun ? json_decode($phaseRun->files_changed_json ?: '[]', true) ?: [] : [];

        return implode("\n\n", array_filter([
            'You are fixing one Miriam Development Manager failure only.',
            'Original phase: '.($phase?->phase_key ?? 'unknown').' / '.($phase?->title ?? 'unknown'),
            'Failure type: '.$failure->failure_type,
            'Severity: '.$failure->severity,
            'Failed command: '.($failure->command ?: 'N/A'),
            'Failure summary: '.($failure->summary ?: $failure->title),
            'Error excerpt:',
            Str::limit((string) $failure->error_excerpt, 2400),
            'Changed files from the failed attempt: '.($changedFiles === [] ? 'none recorded' : implode(', ', $changedFiles)),
            'Safety rules:',
            '- Fix only the current failure.',
            '- Do not start the next phase.',
            '- Do not add unrelated features.',
            '- Do not deploy.',
            '- Do not use Git as the required workflow.',
            '- Do not edit .env.',
            '- Do not run destructive database commands such as migrate:fresh, db:wipe, DROP TABLE, TRUNCATE, or DELETE FROM.',
            '- Preserve existing Product Brain, Slack, Universal Inbox, tasks, projects, health, and runner behavior.',
            'At the end of your response, include the mandatory MIRIAM_RESULT_JSON block.',
            'MIRIAM_RESULT_JSON:',
            '```json
{
  "project": "miriam",
  "phase_key": "'.($phase?->phase_key ?? '<phase_key>').'",
  "status": "passed|failed|blocked|review_required",
  "summary": "",
  "files_changed": [],
  "migrations_added": [],
  "routes_added": [],
  "commands_added": [],
  "tests_added": [],
  "validation": {},
  "blockers": [],
  "next_recommended_action": ""
}
```',
        ]));
    }
}
