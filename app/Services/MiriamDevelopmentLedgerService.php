<?php

namespace App\Services;

use App\Models\MiriamDevelopmentFailure;
use App\Models\MiriamDevelopmentJob;
use App\Models\MiriamDevelopmentLedger;
use App\Models\MiriamDevelopmentPhaseRun;
use App\Models\MiriamManagedApp;
use App\Models\MiriamPromptPhase;
use Illuminate\Support\Collection;

class MiriamDevelopmentLedgerService
{
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

        return MiriamDevelopmentLedger::create([
            'app_id' => $app?->id,
            'app_name' => $app?->name ?: ($job->program?->name ?: 'Miriam'),
            'master_vision_reference' => $this->masterVisionReference($app, $job),
            'job_id' => $job->id,
            'phase_run_id' => $phaseRun?->id,
            'phase_id' => $phase?->id,
            'phase_name' => $phase?->title ?: $phase?->phase_key,
            'status' => $status,
            'summary' => $summary,
            'files_changed_json' => json_encode(array_values($filesChanged), JSON_PRETTY_PRINT),
            'tests_run_json' => json_encode(array_values($testsRun), JSON_PRETTY_PRINT),
            'test_result' => $meta['test_result'] ?? $this->testResult($validation),
            'commit_hash' => $commitHash,
            'deployment_status' => $meta['deployment_status'] ?? $this->deploymentStatus($job),
            'blocker_reason' => $blocker,
            'next_action' => $meta['next_action'] ?? $this->nextAction($job, $blocker),
            'completed_at' => in_array($status, ['completed', 'failed', 'blocked', 'needs_human', 'deployed'], true) ? now() : null,
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
            'Completed: '.$this->compactLedgerList($ledgers->where('status', 'completed')->take(3)),
            'Changed: '.$this->compactFiles($latest),
            'Tests: '.($latest->test_result ?: 'not recorded'),
            'Commit: '.($latest->commit_hash ?: 'none recorded'),
            'Blockers: '.$this->compactLedgerList($ledgers->whereIn('status', ['blocked', 'failed', 'needs_human'])->take(3), 'blocker_reason'),
            'Next: '.($latest->next_action ?: 'No next action recorded.'),
        ]);
    }

    public function blockersText(): string
    {
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
        $jobs = MiriamDevelopmentJob::query()
            ->with(['events'])
            ->whereIn('status', ['waiting_for_approval', 'waiting_for_manual_fix'])
            ->where('updated_at', '<=', now()->subHours($olderThanHours))
            ->get();

        $archived = 0;

        foreach ($jobs as $job) {
            if ($job->events->contains('event_type', 'stale_approval_notice_archived')) {
                continue;
            }

            $archived++;

            if (! $dryRun) {
                $job->events()->create([
                    'event_type' => 'stale_approval_notice_archived',
                    'title' => 'Stale approval/manual-fix notice archived',
                    'body' => 'Slack notice was archived from active attention. Job gate and audit history were not changed.',
                    'meta_json' => json_encode(['older_than_hours' => $olderThanHours], JSON_PRETTY_PRINT),
                ]);
            }
        }

        return ['archived' => $archived, 'dry_run' => $dryRun];
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

    private function hasSafetyGate(MiriamDevelopmentJob $job): bool
    {
        if ($this->textHasSafetyGate($job->error_message ?: '')) {
            return true;
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

        foreach (['destructive', 'production deploy', '.env', 'secret', 'credential', 'delete', 'payment', 'billing', 'external message'] as $needle) {
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
}
