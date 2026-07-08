<?php

namespace App\Console\Commands;

use App\Services\Health\MedicationReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RepairDuplicateMedicationDoseLogs extends Command
{
    protected $signature = 'medication:repair-duplicate-dose-logs
        {--dry-run : Inspect duplicate active dose logs without changing them}
        {--pretend-now= : Test-only current time in Asia/Dubai}';

    protected $description = 'Safely supersede duplicate active medication dose logs without deleting records.';

    public function handle(MedicationReminderService $reminders): int
    {
        $pretendNow = (string) $this->option('pretend-now');
        $now = $pretendNow !== ''
            ? CarbonImmutable::parse($pretendNow, MedicationReminderService::DEFAULT_TIMEZONE)->utc()
            : CarbonImmutable::now('UTC');

        $result = $reminders->repairDuplicateActiveDoseLogs($now, (bool) $this->option('dry-run'));

        $this->info(sprintf(
            'Medication duplicate dose log repair%s: inspected=%d closed=%d skipped=%d.',
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
