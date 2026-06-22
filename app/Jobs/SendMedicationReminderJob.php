<?php

namespace App\Jobs;

use App\Services\Health\MedicationReminderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendMedicationReminderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $doseLogId,
        public readonly string $channel = 'database',
    ) {
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(MedicationReminderService $reminders): void
    {
        $reminders->deliverReminder($this->doseLogId, $this->channel);
    }
}
