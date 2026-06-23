<?php

namespace App\Console\Commands;

use App\Services\MiriamReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendMiriamReminders extends Command
{
    protected $signature = 'miriam:send-reminders {--pretend-now= : Test-only current time in Asia/Dubai}';

    protected $description = 'Send due general Miriam reminders.';

    public function handle(MiriamReminderService $reminders): int
    {
        $pretendNow = (string) $this->option('pretend-now');
        $now = $pretendNow !== ''
            ? CarbonImmutable::parse($pretendNow, MiriamReminderService::DEFAULT_TIMEZONE)->utc()
            : CarbonImmutable::now('UTC');

        $sent = $reminders->sendDueReminders($now);

        $this->info("Miriam reminders processed: {$sent} sent.");

        return self::SUCCESS;
    }
}
