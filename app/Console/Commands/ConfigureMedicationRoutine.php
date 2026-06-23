<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Health\MedicationReminderService;
use Illuminate\Console\Command;

class ConfigureMedicationRoutine extends Command
{
    protected $signature = 'miriam:medication-routine:configure {--user= : User email or id} {--breakfast= : Morning dose reminder time, HH:MM in Asia/Dubai} {--dinner= : Evening dose reminder time, HH:MM in Asia/Dubai}';

    protected $description = 'Configure the user-provided Miriam medication routine without medical advice.';

    public function handle(MedicationReminderService $reminders): int
    {
        $breakfast = (string) $this->option('breakfast');
        $dinner = (string) $this->option('dinner');

        if (! preg_match('/^\d{2}:\d{2}$/', $breakfast) || ! preg_match('/^\d{2}:\d{2}$/', $dinner)) {
            $this->error('Exact breakfast and dinner reminder times are required in HH:MM format, Asia/Dubai.');

            return self::INVALID;
        }

        $user = $this->resolveUser((string) $this->option('user'));

        if (! $user) {
            $this->error('No user was found. Pass --user={email-or-id}.');

            return self::FAILURE;
        }

        $schedules = $reminders->configureDailyRoutine($user, $breakfast, $dinner);

        $this->info('Medication routine configured in Asia/Dubai using only the user-provided medication list.');
        $schedules->each(fn ($schedule) => $this->line("{$schedule->dose_key}: {$schedule->schedule_time} / {$schedule->dosage_text} / {$schedule->timing_note}"));

        return self::SUCCESS;
    }

    private function resolveUser(string $value): ?User
    {
        if ($value !== '') {
            return User::query()
                ->where('email', $value)
                ->orWhere('id', ctype_digit($value) ? (int) $value : 0)
                ->first();
        }

        return User::query()->orderBy('id')->first();
    }
}
