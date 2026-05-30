<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Services\Automation\AutomationRuleService;
use Illuminate\Console\Command;

class RunAutomations extends Command
{
    protected $signature = 'miriam:run-automations {--workspace_id=}';

    protected $description = 'Run safe Miriam automation checks for active workspace rules.';

    public function handle(AutomationRuleService $automationRuleService): int
    {
        $workspace = null;

        if ($this->option('workspace_id')) {
            $workspace = Workspace::query()->findOrFail((int) $this->option('workspace_id'));
        }

        $stats = $automationRuleService->run($workspace);

        $this->info(sprintf(
            'Automations processed: %d rules, %d executed, %d skipped, %d notifications.',
            $stats['rules'],
            $stats['executed'],
            $stats['skipped'],
            $stats['notifications'],
        ));

        return self::SUCCESS;
    }
}
