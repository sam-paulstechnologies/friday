<?php

namespace App\Services;

use App\Models\MiriamDevelopmentFailure;
use App\Models\MiriamDevelopmentJob;
use App\Models\MiriamDevelopmentLedger;
use App\Models\MiriamDevelopmentPhaseRun;
use App\Models\MiriamManagedApp;
use App\Models\MiriamPromptPhase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MiriamDevelopmentLedgerService
{
    private const QUIET_MODE_ENABLED_AT = '2026-06-23 00:00:00';

    public function recordJob(
        MiriamDevelopmentJob $job,
        string $status,
        string $summary,
        ?MiriamDevelopmentPhaseRun $phaseRun = null,
        array $meta = [],
    ): MiriamDevelopmentLedger {
        $job->loadMissing(['managedApp', 'program', 'currentPhase', 'releasePackages']);
        $phaseRun?->loadMissing(['phase']);
        $app = $job->managedApp;
        $phase = $phaseRun?->phase ?: $job->currentPhase;
        $validation = $phaseRun?->validation() ?: ($meta['validation'] ?? []);
        $filesChanged = $meta['files_changed'] ?? $this->phaseFilesChanged($phaseRun);
        $testsRun = $meta['tests_run'] ?? $this->testsFromValidation($validation);
        $blocker = $meta['blocker_reason'] ?? $this->blockerReason($job);
        $commitHash = $meta['commit_hash'] ?? $this->commitFromMeta($phaseRun?->parsedResult() ?: []);
        $phaseId = $phase?->id;
        $completedAt = in_array($status, ['completed', 'failed', 'blocked', 'needs_human', 'deployed'], true) ? now() : null;

        return MiriamDevelopmentLedger::create([
            'app_id' => $app?->id,
            'app_name' => $app?->name ?: ($job->program?->name ?: 'Miriam'),
            'master_vision_reference' => $this->masterVisionReference($app, $job),
            'job_id' => $job->id,
            'phase_run_id' => $phaseRun?->id,
            'phase_id' => $phaseId,
            'phase_name' => $phase?->title ?: $phase?->phase_key,
            'development_name' => $meta['development_name'] ?? $this->developmentName($job, $phaseRun, $summary),
            'short_description' => $meta['short_description'] ?? $this->shortDescription($job, $phaseRun, $summary),
            'status' => $status,
            'summary' => $summary,
            'files_changed_json' => json_encode(array_values($filesChanged), JSON_PRETTY_PRINT),
            'tests_run_json' => json_encode(array_values($testsRun), JSON_PRETTY_PRINT),
            'test_result' => $meta['test_result'] ?? $this->testResult($validation),
            'commit_hash' => $commitHash,
            'deployment_status' => $meta['deployment_status'] ?? $this->deploymentStatus($job),
            'blocker_reason' => $blocker,
            'next_action' => $meta['next_action'] ?? $this->nextAction($job, $blocker),
            'notification_dedupe_key' => $this->notificationDedupeKey($app?->id, $job->id, $phaseId, $status, $summary),
            'started_notification_dedupe_key' => $this->startedNotificationDedupeKey($app?->id, $job->id, $phaseId),
            'completed_at' => $completedAt,
        ]);
    }

    public function recordPhaseResult(MiriamDevelopmentJob $job, MiriamDevelopmentPhaseRun $phaseRun, array $meta = []): MiriamDevelopmentLedger
    {
        $job = $job->fresh(['managedApp', 'program', 'currentPhase', 'releasePackages']) ?: $job;
        $phaseRun = $phaseRun->fresh(['phase']) ?: $phaseRun;

        $status = match ($job->status) {
            'completed' => 'completed',
            'failed' => 'failed',
            'blocked' => 'blocked',
            'waiting_for_approval', 'waiting_for_manual_fix' => $this->hasSafetyGate($job) ? 'needs_human' : 'completed',
            default => in_array($phaseRun->status, ['failed', 'blocked'], true) ? $phaseRun->status : 'running',
        };

        return $this->recordJob(
            $job,
            $status,
            $meta['summary'] ?? $this->phaseSummary($job, $phaseRun),
            $phaseRun,
            $meta
        );
    }

    public function dashboard(): array
    {
        if (! Schema::hasTable('miriam_managed_apps') || ! Schema::hasTable('miriam_development_ledgers')) {
            return [];
        }

        return MiriamManagedApp::query()
            ->with(['promptProgram.phases' => fn ($query) => $query->orderBy('sort_order')])
            ->orderBy('name')
            ->get()
            ->map(fn (MiriamManagedApp $app) => $this->appDashboard($app))
            ->values()
            ->all();
    }

    public function appDashboard(MiriamManagedApp $app): array
    {
        $ledgers = MiriamDevelopmentLedger::query()
            ->where('app_id', $app->id)
            ->latest()
            ->limit(50)
            ->get();
        $latest = $ledgers->first();
        $config = $app->config();
        $focus = $config['development_focus'] ?? [];

        return [
            'app_id' => $app->id,
            'app_name' => $app->name,
            'app_slug' => $app->slug,
            'master_vision' => $this->masterVisionReference($app),
            'roadmap_phases' => $app->promptProgram?->phases?->map(fn (MiriamPromptPhase $phase) => [
                'phase_key' => $phase->phase_key,
                'title' => $phase->title,
                'status' => $phase->status,
            ])->values()->all() ?? [],
            'completed_work' => $this->ledgerLines($ledgers->where('status', 'completed')->take(5)),
            'current_work' => $this->ledgerLines($ledgers->whereIn('status', ['running', 'planned'])->take(5)),
            'due_next' => $focus['next_action'] ?? $latest?->next_action ?? 'No queued next action recorded.',
            'blockers' => $this->ledgerLines($ledgers->whereIn('status', ['blocked', 'failed', 'needs_human'])->take(5), 'blocker_reason'),
            'latest_commit' => $ledgers->firstWhere('commit_hash')?->commit_hash,
            'demo_readiness' => $focus['demo_readiness'] ?? 'unknown',
            'production_readiness' => $focus['production_readiness'] ?? $latest?->deployment_status ?? 'manual review required',
            'latest_status' => $latest?->status ?? 'planned',
        ];
    }

    public function developmentSummaryText(?string $slug = null): string
    {
        if (! Schema::hasTable('miriam_development_ledgers')) {
            return "*Miriam development update*\nDevelopment ledger table is not installed yet. Run migrations.";
        }

        if ($slug && ! Schema::hasTable('miriam_managed_apps')) {
            return "*Miriam development update*\nManaged app table is not installed yet. Run migrations.";
        }

        $app = $slug ? MiriamManagedApp::where('slug', $slug)->first() : null;
        $ledgers = MiriamDevelopmentLedger::query()
            ->when($app, fn ($query) => $query->where('app_id', $app->id))
            ->latest()
            ->limit(12)
            ->get();
        $title = $app ? "Miriam development update for {$app->name}" : 'Miriam development update';

        if ($ledgers->isEmpty()) {
            return "*{$title}*\nNo ledger activity has been recorded yet.";
        }

        $latest = $ledgers->first();

        return implode("\n", [
            "*{$title}*",
            $this->compactLedgerTable($ledgers->take(8)),
        ]);
    }

    public function notifyStartedIfNeeded(MiriamDevelopmentLedger $ledger, ?string $goal = null): array
    {
        $ledger->refresh();

        if ($ledger->status !== 'running') {
            return ['sent' => false, 'reason' => 'ledger_status_not_startable'];
        }

        if ($ledger->started_notified_at) {
            return ['sent' => false, 'reason' => 'started_already_notified'];
        }

        if ($this->isHistoricalLedger($ledger)) {
            return ['sent' => false, 'reason' => 'historical_ledger_suppressed'];
        }

        $key = $ledger->started_notification_dedupe_key ?: $this->startedNotificationDedupeKey(
            $ledger->app_id,
            $ledger->job_id,
            $ledger->phase_id,
        );

        if (! $ledger->started_notification_dedupe_key) {
            $ledger->forceFill(['started_notification_dedupe_key' => $key])->save();
        }

        $alreadySent = MiriamDevelopmentLedger::query()
            ->where('started_notification_dedupe_key', $key)
            ->whereNotNull('started_notified_at')
            ->where('id', '!=', $ledger->id)
            ->exists();

        if ($alreadySent) {
            return ['sent' => false, 'reason' => 'durable_started_duplicate_suppressed'];
        }

        $response = app(MiriamSmartSlackNotificationService::class)->notifyDevelopmentStarted(
            $this->shortSlackCell($ledger->development_name ?: $this->developmentNameFromLedger($ledger)),
            $this->shortSlackCell($goal ?: $ledger->short_description ?: $ledger->summary ?: $ledger->next_action ?: 'Run the assigned development phase.'),
            $ledger->job_id,
            $ledger->phase_id,
        );

        if ($response['sent'] ?? false) {
            $ledger->forceFill(['started_notified_at' => now()])->save();
        }

        return $response + ['started_notification_dedupe_key' => $key];
    }

    public function notifySummaryIfNeeded(MiriamDevelopmentLedger $ledger): array
    {
        $ledger->refresh();

        if (! in_array($ledger->status, ['completed', 'deployed'], true)) {
            return ['sent' => false, 'reason' => 'ledger_status_not_notifiable'];
        }

        if ($ledger->summary_notified_at) {
            return ['sent' => false, 'reason' => 'summary_already_notified'];
        }

        if ($this->isHistoricalLedger($ledger)) {
            return ['sent' => false, 'reason' => 'historical_ledger_suppressed'];
        }

        if (! $this->hasCompletionSignal($ledger)) {
            return ['sent' => false, 'reason' => 'empty_completion_signal_suppressed'];
        }

        $key = $this->completedNotificationDedupeKey(
            $ledger->app_id,
            $ledger->job_id,
            $ledger->phase_id,
        );

        if ($ledger->notification_dedupe_key !== $key) {
            $ledger->forceFill(['notification_dedupe_key' => $key])->save();
        }

        $alreadySent = MiriamDevelopmentLedger::query()
            ->where('notification_dedupe_key', $key)
            ->whereNotNull('summary_notified_at')
            ->where('id', '!=', $ledger->id)
            ->exists();

        if ($alreadySent) {
            return ['sent' => false, 'reason' => 'durable_duplicate_suppressed'];
        }

        $summary = $this->completionCardSummary($ledger);
        $response = app(MiriamSmartSlackNotificationService::class)->notifyDevelopmentCompleted(
            $this->shortSlackCell($ledger->development_name ?: $this->developmentNameFromLedger($ledger)),
            $summary,
            $ledger->job_id,
            $ledger->phase_id,
            [
                'summary_hash' => sha1($key),
                'notification_dedupe_key' => $key,
            ]
        );

        if ($response['sent'] ?? false) {
            $ledger->forceFill(['summary_notified_at' => now()])->save();
        }

        return $response + ['notification_dedupe_key' => $key];
    }

    public function blockersText(): string
    {
        if (! Schema::hasTable('miriam_development_ledgers')) {
            return "Miriam development ledger table is not installed yet. Run migrations.";
        }

        $blockers = MiriamDevelopmentLedger::query()
            ->whereIn('status', ['blocked', 'failed', 'needs_human'])
            ->latest()
            ->limit(10)
            ->get();

        if ($blockers->isEmpty()) {
            return "No active Miriam development blockers are recorded in the ledger.";
        }

        return "*Miriam development blockers*\n".$blockers
            ->map(fn (MiriamDevelopmentLedger $ledger) => "- {$ledger->app_name}: {$ledger->status} / ".($ledger->blocker_reason ?: $ledger->summary ?: 'No detail recorded.'))
            ->implode("\n");
    }

    public function nextText(): string
    {
        if (! Schema::hasTable('miriam_development_ledgers')) {
            return "Miriam development ledger table is not installed yet. Run migrations.";
        }

        $next = MiriamDevelopmentLedger::query()
            ->whereNotNull('next_action')
            ->latest()
            ->limit(8)
            ->get();

        if ($next->isEmpty()) {
            return "No Miriam development next actions are recorded yet.";
        }

        return "*Miriam development next actions*\n".$next
            ->map(fn (MiriamDevelopmentLedger $ledger) => "- {$ledger->app_name}: {$ledger->next_action}")
            ->implode("\n");
    }

    public function completedTodayText(): string
    {
        if (! Schema::hasTable('miriam_development_ledgers')) {
            return "Miriam development ledger table is not installed yet. Run migrations.";
        }

        $completed = MiriamDevelopmentLedger::query()
            ->where('status', 'completed')
            ->whereDate('completed_at', now()->toDateString())
            ->latest('completed_at')
            ->limit(12)
            ->get();

        if ($completed->isEmpty()) {
            return "No Miriam development ledger items were completed today.";
        }

        return "*Miriam completed today*\n".$completed
            ->map(fn (MiriamDevelopmentLedger $ledger) => "- {$ledger->app_name}: {$ledger->summary}")
            ->implode("\n");
    }

    public function archiveStaleApprovalNotices(int $olderThanHours = 24, bool $dryRun = false): array
    {
        if (! Schema::hasTable('miriam_development_jobs')) {
            return [
                'archived' => 0,
                'dry_run' => $dryRun,
                'skipped_reason' => 'miriam_development_jobs table is missing. Run migrations before archiving stale approval notices.',
            ];
        }

        if (! Schema::hasTable('miriam_development_job_events')) {
            return [
                'archived' => 0,
                'dry_run' => $dryRun,
                'skipped_reason' => 'miriam_development_job_events table is missing. Run migrations before archiving stale approval notices.',
            ];
        }

        $jobs = MiriamDevelopmentJob::query()
            ->whereIn('status', ['waiting_for_approval', 'waiting_for_manual_fix'])
            ->where('updated_at', '<=', now()->subHours($olderThanHours))
            ->get();

        $archived = 0;
        $safetyGatesPreserved = 0;

        foreach ($jobs as $job) {
            if ($this->hasSafetyGate($job)) {
                $safetyGatesPreserved++;

                continue;
            }

            if (DB::table('miriam_development_job_events')
                ->where('development_job_id', $job->id)
                ->where('event_type', 'stale_approval_notice_archived')
                ->exists()) {
                continue;
            }

            $archived++;

            if (! $dryRun) {
                DB::table('miriam_development_job_events')->insert([
                    'development_job_id' => $job->id,
                    'event_type' => 'stale_approval_notice_archived',
                    'title' => 'Stale approval/manual-fix notice archived',
                    'body' => 'Slack notice was archived from active attention. Job gate and audit history were not changed.',
                    'meta_json' => json_encode(['older_than_hours' => $olderThanHours], JSON_PRETTY_PRINT),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return [
            'archived' => $archived,
            'dry_run' => $dryRun,
            'safety_gates_preserved' => $safetyGatesPreserved,
            'skipped_reason' => null,
        ];
    }

    public function quietModeEnabledAt(): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::parse(self::QUIET_MODE_ENABLED_AT, config('app.timezone'));
    }

    private function phaseSummary(MiriamDevelopmentJob $job, MiriamDevelopmentPhaseRun $phaseRun): string
    {
        $phase = $phaseRun->phase?->title ?: "Phase run #{$phaseRun->id}";

        return "{$phase} finished with job status {$job->status} and phase status {$phaseRun->status}.";
    }

    private function phaseFilesChanged(?MiriamDevelopmentPhaseRun $phaseRun): array
    {
        if (! $phaseRun) {
            return [];
        }

        return json_decode($phaseRun->files_changed_json ?: '[]', true) ?: [];
    }

    private function testsFromValidation(array $validation): array
    {
        return collect($validation)->keys()->values()->all();
    }

    private function testResult(array $validation): ?string
    {
        if ($validation === []) {
            return null;
        }

        return collect($validation)->contains(fn ($value) => str_contains(strtolower((string) $value), 'fail'))
            ? 'failed'
            : 'passed';
    }

    private function blockerReason(MiriamDevelopmentJob $job): ?string
    {
        if (! in_array($job->status, ['blocked', 'failed', 'waiting_for_manual_fix', 'waiting_for_approval'], true)) {
            return null;
        }

        return $job->error_message;
    }

    private function nextAction(MiriamDevelopmentJob $job, ?string $blocker): string
    {
        if ($blocker) {
            return 'Review the blocker in Miriam Development Manager; Slack will only interrupt for true safety gates or final summaries.';
        }

        return match ($job->status) {
            'completed' => 'Review ledger summary and create a manual release package only when ready.',
            'waiting_for_runner' => 'Start or check the registered runner.',
            'running', 'queued' => 'Let the runner continue; normal progress stays quiet in Slack.',
            default => 'Open Miriam Development Manager for the next safe action.',
        };
    }

    private function deploymentStatus(MiriamDevelopmentJob $job): string
    {
        $package = $job->releasePackages->sortByDesc('created_at')->first();

        return $package?->status ?: 'not_deployed';
    }

    private function masterVisionReference(?MiriamManagedApp $app, ?MiriamDevelopmentJob $job = null): ?string
    {
        if ($app) {
            $config = $app->config();

            return $config['master_vision_reference']
                ?? data_get($config, 'development_focus.master_vision')
                ?? $app->promptProgram?->slug
                ?? $app->notes;
        }

        return $job?->program?->slug;
    }

    private function commitFromMeta(array $parsed): ?string
    {
        $hash = $parsed['commit_hash'] ?? data_get($parsed, 'git.commit') ?? null;

        return is_string($hash) ? $hash : null;
    }

    public function hasSafetyGate(MiriamDevelopmentJob $job): bool
    {
        if ($this->textHasSafetyGate($job->error_message ?: '')) {
            return true;
        }

        if (! Schema::hasTable('miriam_development_failures')) {
            return false;
        }

        return MiriamDevelopmentFailure::query()
            ->where('development_job_id', $job->id)
            ->where(function ($query): void {
                $query->where('needs_user_at_system', true)
                    ->orWhere('severity', 'critical')
                    ->orWhereIn('failure_type', ['safety_risk', 'manual_credentials_needed']);
            })
            ->exists();
    }

    private function textHasSafetyGate(string $text): bool
    {
        $haystack = strtolower($text);

        foreach (['destructive', 'production deploy', '.env', 'secret', 'credential', 'delete', 'payment', 'billing', 'external message', 'safety gate'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function ledgerLines(Collection $ledgers, string $field = 'summary'): array
    {
        return $ledgers
            ->map(fn (MiriamDevelopmentLedger $ledger) => [
                'status' => $ledger->status,
                'summary' => $ledger->{$field} ?: $ledger->summary,
                'phase_name' => $ledger->phase_name,
                'created_at' => $ledger->created_at?->toDateTimeString(),
            ])
            ->values()
            ->all();
    }

    private function compactLedgerList(Collection $ledgers, string $field = 'summary'): string
    {
        if ($ledgers->isEmpty()) {
            return 'none';
        }

        return $ledgers
            ->map(fn (MiriamDevelopmentLedger $ledger) => ($ledger->{$field} ?: $ledger->summary ?: $ledger->phase_name ?: 'recorded item'))
            ->implode('; ');
    }

    private function compactFiles(MiriamDevelopmentLedger $ledger): string
    {
        $files = $ledger->filesChanged();

        if ($files === []) {
            return 'none recorded';
        }

        return implode(', ', array_slice($files, 0, 5)).(count($files) > 5 ? ' +' . (count($files) - 5).' more' : '');
    }

    public function notificationDedupeKey(?int $appId, ?int $jobId, ?int $phaseId, string $status, string $summary): string
    {
        return sha1(implode(':', [
            $appId ?: 'none',
            $jobId ?: 'none',
            $phaseId ?: 'none',
            $status,
            sha1($summary),
        ]));
    }

    public function completedNotificationDedupeKey(?int $appId, ?int $jobId, ?int $phaseId): string
    {
        return sha1(implode(':', [
            'completed',
            $appId ?: 'none',
            $jobId ?: 'none',
            $phaseId ?: 'none',
        ]));
    }

    public function startedNotificationDedupeKey(?int $appId, ?int $jobId, ?int $phaseId): string
    {
        return sha1(implode(':', [
            'started',
            $appId ?: 'none',
            $jobId ?: 'none',
            $phaseId ?: 'none',
        ]));
    }

    public function compactLedgerTableText(?string $slug = null, int $limit = 10): string
    {
        $rows = $this->compactLedgerRows($slug, $limit);

        if ($rows === []) {
            return 'No ledger activity recorded.';
        }

        return collect($rows)
            ->map(fn (array $row) => implode('; ', [
                'App: '.$row[0],
                'Work done: '.$row[1],
                'Status: '.$row[2],
                'Commit: '.$row[3],
                'Tests: '.$row[4],
                'Deployment: '.$row[5],
                'Next: '.$row[6],
            ]))
            ->implode("\n");
    }

    public function compactLedgerRows(?string $slug = null, int $limit = 10): array
    {
        if (! Schema::hasTable('miriam_development_ledgers')) {
            return [];
        }

        $app = null;
        if ($slug && Schema::hasTable('miriam_managed_apps')) {
            $app = MiriamManagedApp::where('slug', $slug)->first();
        }

        $ledgers = MiriamDevelopmentLedger::query()
            ->when($app, fn ($query) => $query->where('app_id', $app->id))
            ->latest()
            ->limit($limit)
            ->get();

        if ($ledgers->isEmpty()) {
            return [];
        }

        return $ledgers->map(fn (MiriamDevelopmentLedger $ledger) => [
            $this->shortSlackCell($ledger->app_name ?: 'Miriam'),
            $this->shortSlackCell($ledger->summary ?: $ledger->phase_name ?: 'Completed work'),
            $this->shortSlackCell($ledger->status ?: 'completed'),
            $this->shortSlackCell($ledger->commit_hash ? substr($ledger->commit_hash, 0, 12) : '-'),
            $this->shortSlackCell($this->testsCell($ledger)),
            $this->shortSlackCell($ledger->deployment_status ?: '-'),
            $this->shortSlackCell($ledger->next_action ?: '-'),
        ])->values()->all();
    }

    private function completedTableText(MiriamDevelopmentLedger $ledger): string
    {
        return $this->compactLedgerTable(collect([$ledger]));
    }

    private function completionCardSummary(MiriamDevelopmentLedger $ledger): array
    {
        return [
            'development_name' => $this->shortSlackCell($ledger->development_name ?: $this->developmentNameFromLedger($ledger)),
            'short_summary' => $this->shortSlackCell($ledger->summary ?: $ledger->phase_name ?: 'Completed work'),
            'status' => $this->shortSlackCell($ledger->status ?: 'completed'),
            'commit' => $this->shortSlackCell($ledger->commit_hash ? substr($ledger->commit_hash, 0, 12) : '-'),
            'tests' => $this->shortSlackCell($this->testsCell($ledger)),
            'deployment' => $this->shortSlackCell($ledger->deployment_status ?: '-'),
            'next' => $this->shortSlackCell($ledger->next_action ?: '-'),
        ];
    }

    private function compactLedgerTable(Collection $ledgers): string
    {
        return collect($this->compactLedgerRowsFromCollection($ledgers))
            ->map(fn (array $row) => implode('; ', [
                'App: '.$row[0],
                'Work done: '.$row[1],
                'Status: '.$row[2],
                'Commit: '.$row[3],
                'Tests: '.$row[4],
                'Deployment: '.$row[5],
                'Next: '.$row[6],
            ]))
            ->implode("\n");
    }

    private function compactLedgerRowsFromCollection(Collection $ledgers): array
    {
        return $ledgers->map(fn (MiriamDevelopmentLedger $ledger) => [
            $this->shortSlackCell($ledger->app_name ?: 'Miriam'),
            $this->shortSlackCell($ledger->summary ?: $ledger->phase_name ?: 'Completed work'),
            $this->shortSlackCell($ledger->status ?: 'completed'),
            $this->shortSlackCell($ledger->commit_hash ? substr($ledger->commit_hash, 0, 12) : '-'),
            $this->shortSlackCell($this->testsCell($ledger)),
            $this->shortSlackCell($ledger->deployment_status ?: '-'),
            $this->shortSlackCell($ledger->next_action ?: '-'),
        ])->values()->all();
    }

    private function hasCompletionSignal(MiriamDevelopmentLedger $ledger): bool
    {
        if (in_array($ledger->status, ['blocked', 'failed', 'needs_human'], true) || filled($ledger->blocker_reason)) {
            return true;
        }

        return $ledger->filesChanged() !== []
            || $ledger->testsRun() !== []
            || filled($ledger->test_result)
            || filled($ledger->commit_hash);
    }

    private function testsCell(MiriamDevelopmentLedger $ledger): string
    {
        $tests = $ledger->testsRun();

        if ($tests !== []) {
            return implode(', ', array_slice($tests, 0, 2)).(count($tests) > 2 ? ' +' . (count($tests) - 2) : '');
        }

        return $ledger->test_result ?: '-';
    }

    private function shortSlackCell(string $value): string
    {
        $value = trim(str_replace(["\r", "\n", '|'], [' ', ' ', '/'], $value));

        return (string) str($value === '' ? '-' : $value)->limit(80);
    }

    private function developmentName(MiriamDevelopmentJob $job, ?MiriamDevelopmentPhaseRun $phaseRun, string $summary): string
    {
        $candidate = $job->title
            ?: $phaseRun?->phase?->title
            ?: $job->currentPhase?->title
            ?: $summary
            ?: 'Miriam development';

        return $this->sixWordName($candidate);
    }

    private function developmentNameFromLedger(MiriamDevelopmentLedger $ledger): string
    {
        return $this->sixWordName($ledger->phase_name ?: $ledger->summary ?: $ledger->app_name ?: 'Miriam development');
    }

    private function shortDescription(MiriamDevelopmentJob $job, ?MiriamDevelopmentPhaseRun $phaseRun, string $summary): string
    {
        $candidate = $phaseRun?->phase?->description
            ?: $summary
            ?: $job->title
            ?: 'Codex is running the assigned Miriam development work.';

        return $this->shortSlackCell($candidate);
    }

    private function sixWordName(string $value): string
    {
        $words = preg_split('/\s+/', trim(Str::of($value)
            ->replaceMatches('/[^\pL\pN\s-]+/u', ' ')
            ->squish()
            ->toString())) ?: [];
        $name = implode(' ', array_slice(array_filter($words), 0, 6));

        return $name !== '' ? $name : 'Miriam development';
    }

    private function isHistoricalLedger(MiriamDevelopmentLedger $ledger): bool
    {
        $quietModeEnabledAt = $this->quietModeEnabledAt();

        if ($ledger->completed_at && $ledger->completed_at->lessThan($quietModeEnabledAt)) {
            return true;
        }

        return $ledger->created_at && $ledger->created_at->lessThan($quietModeEnabledAt);
    }
}
