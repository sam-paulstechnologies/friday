<?php

namespace App\Console\Commands;

use App\Services\MiriamDevelopmentLedgerService;
use Illuminate\Console\Command;

class MiriamDevelopmentArchiveStaleApprovals extends Command
{
    protected $signature = 'miriam:dev:archive-stale-approvals {--older-than-hours=24 : Minimum age for stale approval/manual-fix notices} {--dry-run : Report only}';

    protected $description = 'Archive stale Miriam approval/manual-fix notices without deleting audit history or changing safety gates.';

    public function handle(MiriamDevelopmentLedgerService $ledger): int
    {
        $hours = max(1, (int) $this->option('older-than-hours'));
        $result = $ledger->archiveStaleApprovalNotices($hours, (bool) $this->option('dry-run'));

        if ($result['skipped_reason'] ?? null) {
            $this->warn($result['skipped_reason']);
            $this->line('Job gates and audit history were preserved.');

            return self::SUCCESS;
        }

        $prefix = $result['dry_run'] ? 'Would archive' : 'Archived';
        $this->info("{$prefix} {$result['archived']} stale Miriam approval/manual-fix notice(s).");
        $this->line(($result['safety_gates_preserved'] ?? 0).' active safety gate notice(s) preserved.');
        $this->line('Job gates and audit history were preserved.');

        return self::SUCCESS;
    }
}
