<?php

namespace App\Console\Commands;

use App\Services\Health\MedicationReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendMedicationReminders extends Command
{
    protected $signature = 'miriam:send-medication-reminders
        {--sync : Deliver due reminders immediately instead of queueing jobs}
        {--test-channel= : Override the delivery channel label for test evidence}
        {--pretend-now= : Test-only current time in Asia/Dubai, requires --test-channel}';

    protected $description = 'Queue due medication reminders and repeat them until acknowledged.';

    public function handle(MedicationReminderService $reminders): int
    {
        $pretendNow = (string) $this->option('pretend-now');
        $testChannel = $this->option('test-channel') ?: null;

        if ($pretendNow !== '' && ! $testChannel) {
            $this->error('--pretend-now is test-only and requires --test-channel.');

            return self::INVALID;
        }

        $now = $pretendNow !== ''
            ? CarbonImmutable::parse($pretendNow, MedicationReminderService::DEFAULT_TIMEZONE)->utc()
            : CarbonImmutable::now('UTC');

        $result = $reminders->queueDueReminders(
            $now,
            (bool) $this->option('sync'),
            $testChannel
        );

        if ($this->output->isVeryVerbose()) {
            $this->line('Medication reminder debug:');
            $this->line('  current UTC time: '.($result['current_utc'] ?? $now->utc()->toDateTimeString()));
            $this->line('  due candidate count: '.($result['due_candidate_count'] ?? 0));

            foreach ($result['skipped'] ?? [] as $skipped) {
                $this->line(sprintf(
                    '  skipped dose_log_id=%s status=%s next_reminder_at=%s reason=%s',
                    $skipped['dose_log_id'] ?? 'unknown',
                    $skipped['status'] ?? 'unknown',
                    $skipped['next_reminder_at'] ?? 'null',
                    $skipped['reason'] ?? 'unknown'
                ));
            }
        }

        $this->info(sprintf(
            'Medication reminders processed: %d queued/delivered, %d quiet-hour suppressed.',
            $result['queued'],
            $result['quiet_hours_suppressed'],
        ));

        return self::SUCCESS;
    }
}
