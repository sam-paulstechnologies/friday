<?php

namespace App\Http\Controllers;

use App\Models\MiriamDevelopmentJob;
use App\Models\MiriamDevelopmentFailure;
use App\Models\MiriamManagedApp;
use App\Models\MiriamReleasePackage;
use App\Models\MiriamRunnerAgent;
use App\Services\DevelopmentFailureRecoveryService;
use App\Services\MiriamAppRegistryService;
use App\Services\MiriamDevelopmentLedgerService;
use App\Services\MiriamDevelopmentManagerService;
use App\Services\MiriamPromptQueueService;
use App\Services\MiriamReleasePackageService;
use App\Services\MiriamRunnerMonitoringService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DevelopmentManagerController extends Controller
{
    public function index(MiriamPromptQueueService $queue, MiriamAppRegistryService $registry, MiriamRunnerMonitoringService $monitor, MiriamDevelopmentLedgerService $ledger): Response
    {
        $program = $queue->activeProgram();

        $jobs = MiriamDevelopmentJob::query()
            ->with([
                'program',
                'managedApp',
                'validationProfile',
                'runnerAgent',
                'currentPhase',
                'phaseRuns' => fn ($query) => $query->with(['phase', 'savedPrompt', 'runnerAgent'])->orderBy('phase_order'),
                'releasePackages' => fn ($query) => $query->with('approvals')->latest(),
                'failures' => fn ($query) => $query->with(['phaseRun.phase', 'fixAttempts'])->latest(),
                'events' => fn ($query) => $query->with('runnerAgent')->latest()->limit(12),
            ])
            ->latest()
            ->limit(12)
            ->get();

        return Inertia::render('ProductBrain/DevelopmentManager', [
            'program' => $program ? [
                'id' => $program->id,
                'name' => $program->name,
                'slug' => $program->slug,
                'status' => $program->status,
            ] : null,
            'runnerAgents' => MiriamRunnerAgent::query()
                ->latest('last_seen_at')
                ->latest()
                ->get()
                ->map(fn (MiriamRunnerAgent $runner) => [
                    'id' => $runner->id,
                    'name' => $runner->name,
                    'slug' => $runner->slug,
                    'machine_name' => $runner->machine_name,
                    'os' => $runner->os,
                    'local_project_path' => $runner->local_project_path,
                    'status' => $runner->status,
                    'last_seen_at' => $runner->last_seen_at?->toDateTimeString(),
                    'last_ip' => $runner->last_ip,
                    'capabilities' => $runner->capabilities(),
                ])
                ->values(),
            'managedApps' => $registry->apps()->map(fn (MiriamManagedApp $app) => $registry->safeConfig($app))->values(),
            'jobs' => $jobs->map(fn (MiriamDevelopmentJob $job) => $this->jobResource($job))->values(),
            'monitorSummary' => $monitor->summary(),
            'developmentLedgerDashboard' => $ledger->dashboard(),
        ]);
    }

    public function start(Request $request, MiriamDevelopmentManagerService $manager): RedirectResponse
    {
        $data = $request->validate([
            'app_slug' => ['nullable', 'string', 'exists:miriam_managed_apps,slug'],
        ]);

        $options = [
            'source' => 'app',
            'no_git_primary_workflow' => true,
            'local_runner_not_implemented' => true,
        ];

        if ($data['app_slug'] ?? null) {
            $manager->startJobForApp($data['app_slug'], auth()->user(), $options);
        } else {
            $manager->startJobFromActiveProgram(auth()->user(), $options);
        }

        return back()->with('success', 'Development job created. Waiting for local runner if none is active.');
    }

    public function startMulti(Request $request, MiriamDevelopmentManagerService $manager): RedirectResponse
    {
        $data = $request->validate([
            'app_slug' => ['nullable', 'string', 'exists:miriam_managed_apps,slug'],
        ]);

        $options = [
            'source' => 'app',
            'run_mode' => 'controlled_multi_phase',
            'multi_phase_enabled' => true,
            'no_git_primary_workflow' => true,
            'stop_on_failure' => true,
            'stop_on_safety_risk' => true,
            'stop_on_parser_unclear' => true,
            'stop_on_manual_approval' => true,
        ];

        if ($data['app_slug'] ?? null) {
            $manager->startJobForApp($data['app_slug'], auth()->user(), $options);
        } else {
            $manager->startJobFromActiveProgram(auth()->user(), $options);
        }

        return back()->with('success', 'Controlled multi-phase job created. The runner will stop on any failure, risk, unclear result, or approval gate.');
    }

    public function cancel(MiriamDevelopmentJob $job, MiriamDevelopmentManagerService $manager): RedirectResponse
    {
        $manager->cancelQueuedJob($job, auth()->user());

        return back()->with('success', 'Queued development job cancelled.');
    }

    public function applyFix(MiriamDevelopmentFailure $failure, DevelopmentFailureRecoveryService $recovery): RedirectResponse
    {
        $recovery->applyFix($failure);

        return back()->with('success', 'Fix attempt queued. The local runner will not continue to the next phase.');
    }

    public function manualFix(MiriamDevelopmentFailure $failure, DevelopmentFailureRecoveryService $recovery): RedirectResponse
    {
        $recovery->markManualAttentionRequired($failure);

        return back()->with('success', 'Manual fix marked as required.');
    }

    public function resumeAfterManualFix(MiriamDevelopmentFailure $failure, DevelopmentFailureRecoveryService $recovery): RedirectResponse
    {
        $recovery->resumeAfterManualFix($failure);

        return back()->with('success', 'Manual validation requested. The runner will validate only this phase.');
    }

    public function rollbackPhase(MiriamDevelopmentFailure $failure, DevelopmentFailureRecoveryService $recovery): RedirectResponse
    {
        $recovery->requestRollback($failure);

        return back()->with('success', 'Rollback instruction queued for this phase.');
    }

    public function stop(MiriamDevelopmentJob $job, DevelopmentFailureRecoveryService $recovery): RedirectResponse
    {
        $recovery->stopJob($job);

        return back()->with('success', 'Development job stopped safely.');
    }

    public function pause(MiriamDevelopmentJob $job, MiriamDevelopmentManagerService $manager): RedirectResponse
    {
        $manager->pauseJob($job, auth()->user());

        return back()->with('success', 'Development job paused.');
    }

    public function resume(MiriamDevelopmentJob $job, MiriamDevelopmentManagerService $manager): RedirectResponse
    {
        $manager->resumeJob($job, auth()->user());

        return back()->with('success', 'Development job resumed.');
    }

    public function createReleasePackage(MiriamDevelopmentJob $job, MiriamReleasePackageService $releases): RedirectResponse
    {
        $package = $releases->requestForJob($job, auth()->user());

        return back()->with('success', "Release package #{$package->id} requested. Deployment is not automated.");
    }

    public function approveReleasePackage(MiriamReleasePackage $releasePackage, MiriamReleasePackageService $releases): RedirectResponse
    {
        $releases->approve($releasePackage, auth()->user());

        return back()->with('success', "Release package #{$releasePackage->id} approved for manual deployment only.");
    }

    public function rejectReleasePackage(MiriamReleasePackage $releasePackage, MiriamReleasePackageService $releases): RedirectResponse
    {
        $releases->reject($releasePackage, auth()->user());

        return back()->with('success', "Release package #{$releasePackage->id} rejected.");
    }

    private function jobResource(MiriamDevelopmentJob $job): array
    {
        return [
            'id' => $job->id,
            'title' => $job->title,
            'status' => $job->status,
            'run_mode' => $job->run_mode,
            'options' => $job->options(),
            'multi_phase_enabled' => (bool) ($job->options()['multi_phase_enabled'] ?? false),
            'total_phases' => $job->total_phases,
            'completed_phases' => $job->completed_phases,
            'started_at' => $job->started_at?->toDateTimeString(),
            'completed_at' => $job->completed_at?->toDateTimeString(),
            'cancelled_at' => $job->cancelled_at?->toDateTimeString(),
            'error_message' => $job->error_message,
            'program' => $job->program ? [
                'id' => $job->program->id,
                'name' => $job->program->name,
            ] : null,
            'managed_app' => $job->managedApp ? [
                'id' => $job->managedApp->id,
                'name' => $job->managedApp->name,
                'slug' => $job->managedApp->slug,
                'tech_stack' => $job->managedApp->tech_stack,
            ] : null,
            'validation_profile' => $job->validationProfile ? [
                'id' => $job->validationProfile->id,
                'name' => $job->validationProfile->name,
                'slug' => $job->validationProfile->slug,
                'commands' => $job->validationProfile->commands(),
            ] : null,
            'local_project_path_snapshot' => $job->local_project_path_snapshot,
            'local_url_snapshot' => $job->local_url_snapshot,
            'runner' => $job->runnerAgent ? [
                'id' => $job->runnerAgent->id,
                'name' => $job->runnerAgent->name,
                'status' => $job->runnerAgent->status,
                'last_seen_at' => $job->runnerAgent->last_seen_at?->toDateTimeString(),
            ] : null,
            'current_phase' => $job->currentPhase ? [
                'id' => $job->currentPhase->id,
                'phase_key' => $job->currentPhase->phase_key,
                'title' => $job->currentPhase->title,
            ] : null,
            'phase_runs' => $job->phaseRuns->map(fn ($phaseRun) => [
                'id' => $phaseRun->id,
                'phase_order' => $phaseRun->phase_order,
                'status' => $phaseRun->status,
                'started_at' => $phaseRun->started_at?->toDateTimeString(),
                'completed_at' => $phaseRun->completed_at?->toDateTimeString(),
                'error_message' => $phaseRun->error_message,
                'parsed_result' => $phaseRun->parsedResult(),
                'validation' => $phaseRun->validation(),
                'files_changed' => json_decode($phaseRun->files_changed_json ?: '[]', true) ?: [],
                'backup_paths' => json_decode($phaseRun->backup_paths_json ?: '[]', true) ?: [],
                'manifest_before_available' => filled($phaseRun->manifest_before_json),
                'manifest_after_available' => filled($phaseRun->manifest_after_json),
                'codex_stdout_preview' => $phaseRun->codex_stdout ? str($phaseRun->codex_stdout)->limit(1200)->toString() : null,
                'codex_stderr_preview' => $phaseRun->codex_stderr ? str($phaseRun->codex_stderr)->limit(1200)->toString() : null,
                'is_dry_run' => (bool) ($phaseRun->parsedResult()['dry_run'] ?? false),
                'is_one_phase_execution' => (bool) ($phaseRun->parsedResult()['one_phase_execution'] ?? false),
                'safety_scanner' => $phaseRun->parsedResult()['safety_scanner'] ?? null,
                'phase' => $phaseRun->phase ? [
                    'phase_key' => $phaseRun->phase->phase_key,
                    'title' => $phaseRun->phase->title,
                ] : null,
                'saved_prompt' => $phaseRun->savedPrompt ? [
                    'title' => $phaseRun->savedPrompt->title,
                    'type' => $phaseRun->savedPrompt->type,
                ] : null,
                'runner' => $phaseRun->runnerAgent ? [
                    'name' => $phaseRun->runnerAgent->name,
                ] : null,
            ])->values(),
            'failures' => $job->failures->map(fn (MiriamDevelopmentFailure $failure) => [
                'id' => $failure->id,
                'failure_type' => $failure->failure_type,
                'severity' => $failure->severity,
                'title' => $failure->title,
                'summary' => $failure->summary,
                'command' => $failure->command,
                'error_excerpt' => $failure->error_excerpt,
                'can_auto_fix' => $failure->can_auto_fix,
                'needs_user_at_system' => $failure->needs_user_at_system,
                'status' => $failure->status,
                'resolved_at' => $failure->resolved_at?->toDateTimeString(),
                'phase' => $failure->phaseRun?->phase ? [
                    'phase_key' => $failure->phaseRun->phase->phase_key,
                    'title' => $failure->phaseRun->phase->title,
                ] : null,
                'fix_attempts' => $failure->fixAttempts->map(fn ($attempt) => [
                    'id' => $attempt->id,
                    'attempt_number' => $attempt->attempt_number,
                    'status' => $attempt->status,
                    'started_at' => $attempt->started_at?->toDateTimeString(),
                    'completed_at' => $attempt->completed_at?->toDateTimeString(),
                    'error_message' => $attempt->error_message,
                ])->values(),
            ])->values(),
            'release_packages' => $job->releasePackages->map(fn (MiriamReleasePackage $package) => [
                'id' => $package->id,
                'title' => $package->title,
                'version_label' => $package->version_label,
                'status' => $package->status,
                'package_path' => $package->package_path,
                'package_size_bytes' => $package->package_size_bytes,
                'packaged_at' => $package->packaged_at?->toDateTimeString(),
                'approved_at' => $package->approved_at?->toDateTimeString(),
                'rejected_at' => $package->rejected_at?->toDateTimeString(),
                'error_message' => $package->error_message,
                'approval_status' => $package->latestApproval()?->status,
                'files_included_count' => count($package->filesIncluded()),
                'files_excluded_count' => count($package->filesExcluded()),
                'validation_summary' => $package->validationSummary(),
                'qa_checklist' => app(MiriamReleasePackageService::class)->qaChecklist($package),
            ])->values(),
            'events' => $job->events->map(fn ($event) => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'title' => $event->title,
                'body' => $event->body,
                'meta' => $event->meta(),
                'is_dry_run' => $event->event_type === 'local_runner_dry_run_received',
                'created_at' => $event->created_at?->toDateTimeString(),
                'runner' => $event->runnerAgent ? [
                    'name' => $event->runnerAgent->name,
                ] : null,
            ])->values(),
        ];
    }
}
