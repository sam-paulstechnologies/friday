<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MiriamDevelopmentMuteNoise extends Command
{
    protected $signature = 'miriam:dev:mute-noise {--dry-run : Report only}';

    protected $description = 'Suppress old normal Miriam development approval/update noise without deleting audit history or weakening safety gates.';

    public function handle(): int
    {
        if (! Schema::hasTable('miriam_development_jobs')) {
            $this->warn('miriam_development_jobs table is missing. Run migrations before muting old development noise.');

            return self::SUCCESS;
        }

        if (! Schema::hasTable('miriam_development_job_events')) {
            $this->warn('miriam_development_job_events table is missing. Run migrations before muting old development noise.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $jobs = DB::table('miriam_development_jobs')
            ->select(['id', 'status'])
            ->whereIn('status', ['waiting_for_approval', 'waiting_for_manual_fix'])
            ->get();
        $muted = 0;

        foreach ($jobs as $job) {
            $alreadyMuted = DB::table('miriam_development_job_events')
                ->where('development_job_id', $job->id)
                ->where('event_type', 'development_noise_muted')
                ->exists();

            if ($alreadyMuted) {
                continue;
            }

            $muted++;

            if (! $dryRun) {
                DB::table('miriam_development_job_events')->insert([
                    'development_job_id' => $job->id,
                    'event_type' => 'development_noise_muted',
                    'title' => 'Normal development notification noise muted',
                    'body' => 'Signal-only Slack mode is active. Normal approval/update noise is suppressed; real safety gates remain active.',
                    'meta_json' => json_encode(['status' => $job->status], JSON_PRETTY_PRINT),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->info(($dryRun ? 'Would mute' : 'Muted')." {$muted} normal Miriam development notification gate(s).");
        $this->line('Safety gates remain active. Audit history was not deleted.');

        return self::SUCCESS;
    }
}
