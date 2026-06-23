<?php

namespace App\Console\Commands;

use App\Models\MiriamDevelopmentLedger;
use App\Models\MiriamManagedApp;
use App\Services\MiriamDevelopmentLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MiriamDevelopmentIngestSummary extends Command
{
    protected $signature = 'miriam:dev:ingest-summary
        {--app= : Managed app slug}
        {--job-id= : Development job id}
        {--phase-id= : Prompt phase id}
        {--phase-name= : Human-readable phase name}
        {--status=completed : planned/running/completed/failed/blocked/needs_human/deployed}
        {--summary= : Summary text}
        {--file=* : File changed}
        {--test=* : Test or validation command run}
        {--test-result= : Test result summary}
        {--commit= : Commit hash}
        {--deployment-status=not_deployed : Deployment status}
        {--blocker= : Blocker reason}
        {--next= : Next action}
        {--dry-run : Report only}';

    protected $description = 'Idempotently ingest a development summary into the Miriam ledger without sending Slack.';

    public function handle(MiriamDevelopmentLedgerService $ledgerService): int
    {
        if (! Schema::hasTable('miriam_development_ledgers')) {
            $this->warn('miriam_development_ledgers table is missing. Run migrations before ingesting summaries.');

            return self::SUCCESS;
        }

        $summary = trim((string) $this->option('summary'));

        if ($summary === '') {
            $this->error('Missing required --summary text.');

            return self::INVALID;
        }

        $app = $this->appFromOption();
        $status = (string) $this->option('status');
        $jobId = $this->existingIdOrNull('miriam_development_jobs', $this->nullableInt($this->option('job-id')), 'job');
        $phaseId = $this->existingIdOrNull('miriam_prompt_phases', $this->nullableInt($this->option('phase-id')), 'phase');
        $dedupeKey = $ledgerService->notificationDedupeKey($app?->id, $jobId, $phaseId, $status, $summary);

        $attributes = [
            'app_id' => $app?->id,
            'app_name' => $app?->name ?: ((string) $this->option('app') ?: 'Miriam'),
            'master_vision_reference' => $app ? $this->visionFor($app) : null,
            'job_id' => $jobId,
            'phase_id' => $phaseId,
            'phase_name' => (string) $this->option('phase-name') ?: null,
            'status' => $status,
            'summary' => $summary,
            'files_changed_json' => json_encode(array_values($this->option('file') ?: []), JSON_PRETTY_PRINT),
            'tests_run_json' => json_encode(array_values($this->option('test') ?: []), JSON_PRETTY_PRINT),
            'test_result' => (string) $this->option('test-result') ?: null,
            'commit_hash' => (string) $this->option('commit') ?: null,
            'deployment_status' => (string) $this->option('deployment-status') ?: 'not_deployed',
            'blocker_reason' => (string) $this->option('blocker') ?: null,
            'next_action' => (string) $this->option('next') ?: null,
            'notification_dedupe_key' => $dedupeKey,
            'completed_at' => in_array($status, ['completed', 'failed', 'blocked', 'needs_human', 'deployed'], true) ? now() : null,
        ];

        if ((bool) $this->option('dry-run')) {
            $this->info('Would ingest Miriam development ledger summary.');
            $this->line('Dedupe key: '.$dedupeKey);

            return self::SUCCESS;
        }

        $ledger = MiriamDevelopmentLedger::query()
            ->where('notification_dedupe_key', $dedupeKey)
            ->first();

        ($ledger ?: new MiriamDevelopmentLedger())->forceFill($attributes)->save();

        $this->info('Ingested Miriam development ledger summary #'.($ledger ?: MiriamDevelopmentLedger::where('notification_dedupe_key', $dedupeKey)->first())->id.'.');
        $this->line('Slack notification was not sent by ingest-summary.');

        return self::SUCCESS;
    }

    private function appFromOption(): ?MiriamManagedApp
    {
        $slug = (string) $this->option('app');

        if ($slug === '' || ! Schema::hasTable('miriam_managed_apps')) {
            return null;
        }

        $app = MiriamManagedApp::query()->where('slug', $slug)->first();

        if (! $app) {
            $this->warn("Managed app not found: {$slug}; ingesting summary as Miriam-level ledger data.");
        }

        return $app;
    }

    private function visionFor(MiriamManagedApp $app): ?string
    {
        $config = $app->config();

        return $config['master_vision_reference']
            ?? data_get($config, 'development_focus.master_vision')
            ?? $this->promptProgramSlug($app)
            ?? $app->notes;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function existingIdOrNull(string $table, ?int $id, string $label): ?int
    {
        if (! $id) {
            return null;
        }

        if (! Schema::hasTable($table)) {
            $this->warn("Cannot verify {$label} #{$id}; {$table} table is missing, so the ledger reference was left empty.");

            return null;
        }

        if (! DB::table($table)->where('id', $id)->exists()) {
            $this->warn("Cannot verify {$label} #{$id}; the ledger reference was left empty.");

            return null;
        }

        return $id;
    }

    private function promptProgramSlug(MiriamManagedApp $app): ?string
    {
        if (! $app->prompt_program_id || ! Schema::hasTable('miriam_prompt_programs')) {
            return null;
        }

        return DB::table('miriam_prompt_programs')
            ->where('id', $app->prompt_program_id)
            ->value('slug');
    }
}
