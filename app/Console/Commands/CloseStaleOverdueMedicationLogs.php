<?php

namespace App\Console\Commands;

use App\Services\Health\MedicationReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class CloseStaleOverdueMedicationLogs extends Command
{
    protected $signature = 'medication:close-stale-overdue
        {--dry-run : Inspect stale overdue dose logs without changing them}
        {--pretend-now= : Test-only current time in Asia/Dubai}';

    protected $description = 'Close stale previous-day medication dose logs that were superseded by newer dose logs.';

    public function handle(MedicationReminderService $reminders): int
    {
        $pretendNow = (string) $this->option('pretend-now');
        $now = $pretendNow !== ''
            ? CarbonImmutable::parse($pretendNow, MedicationReminderService::DEFAULT_TIMEZONE)->utc()
            : CarbonImmutable::now('UTC');

        $result = $reminders->closeStaleOverdueLogs($now, (bool) $this->option('dry-run'));

        $this->info(sprintf(
            'Stale medication overdue cleanup%s: inspected=%d closed=%d skipped=%d.',
            $result['dry_run'] ? ' dry-run' : '',
            $result['inspected'],
            $result['closed'],
            $result['skipped'],
        ));

        if ($this->output->isVerbose() && $result['closed_ids'] !== []) {
            $this->line('Closed dose log IDs: '.implode(', ', $result['closed_ids']));
        }

        return self::SUCCESS;
    }
}
