<?php

namespace App\Services;

use App\Models\MiriamDevelopmentJob;
use App\Models\MiriamDevelopmentJobEvent;
use App\Models\MiriamDevelopmentPhaseRun;
use App\Models\MiriamDevelopmentFailure;
use App\Models\MiriamPromptPhase;
use App\Models\MiriamPromptProgram;
use App\Models\MiriamRunnerAgent;
use App\Models\MiriamSavedPrompt;
use App\Models\User;
use Illuminate\Support\Collection;

class MiriamDevelopmentManagerService
{
    public function __construct(
        private readonly MiriamPromptQueueService $promptQueue,
        private readonly CodexResultParserService $parser,
        private readonly MiriamAppRegistryService $appRegistry,
        private readonly MiriamWorkspaceRouterService $workspaceRouter,
        private readonly MiriamDevelopmentLedgerService $ledger,
    ) {
    }

    public function startJobFromActiveProgram(?User $user = null, array $options = []): MiriamDevelopmentJob
    {
        $program = $this->promptQueue->activeProgram();

        abort_unless($program, 422, 'No active Prompt OS program found.');

        return $this->startJob($program, $user, $options);
    }

    public function startJobForApp(string $appSlug, ?User $user = null, array $options = []): MiriamDevelopmentJob
    {
        $app = $this->appRegistry->resolve($appSlug);

        abort_unless($app, 404, "Managed app {$appSlug} was not found.");

        $context = $this->workspaceRouter->resolveForJob($app);
        $validationMode = $options['validation_mode'] ?? match ($options['run_mode'] ?? null) {
            'app_dry_run' => 'app_dry_run',
            'validation_only' => 'validation_only',
            default => 'codex',
        };

        return $this->startJob($context['prompt_program'], $user, array_merge($options, [
            'managed_app_id' => $app->id,
            'validation_profile_id' => $context['validation_profile']->id,
            'runner_agent_id' => $context['runner']?->id,
            'local_project_path_snapshot' => $context['local_project_path'],
            'local_url_snapshot' => $context['local_url'],
            'app_context' => [
                'managed_app_slug' => $app->slug,
                'managed_app_name' => $app->name,
                'tech_stack' => $context['tech_stack'],
                'local_project_path' => $context['local_project_path'],
                'local_url' => $context['local_url'],
                'backup_path' => $context['backup_path'],
                'release_path' => $context['release_path'],
                'validation_commands' => $context['validation_commands'],
                'validation_profile' => [
                    'id' => $context['validation_profile']->id,
                    'name' => $context['validation_profile']->name,
                    'slug' => $context['validation_profile']->slug,
                    'stack_type' => $context['validation_profile']->stack_type,
                ],
                'validation_mode' => $validationMode,
                'app_health' => $context['health'],
            ],
        ]));
    }

    public function startAppDryRun(string $appSlug, ?User $user = null, array $options = []): MiriamDevelopmentJob
    {
        return $this->startJobForApp($appSlug, $user, array_merge($options, [
            'run_mode' => 'app_dry_run',
            'validation_mode' => 'app_dry_run',
            'dry_run_only' => true,
        ]));
    }

    public function startAppValidationOnly(string $appSlug, ?User $user = null, array $options = []): MiriamDevelopmentJob
    {
        return $this->startJobForApp($appSlug, $user, array_merge($options, [
            'run_mode' => 'validation_only',
            'validation_mode' => 'validation_only',
            'codex_execution' => 'skipped',
        ]));
    }

    public function startJob(MiriamPromptProgram $program, ?User $user = null, array $options = []): MiriamDevelopmentJob
    {
        $phasePrompts = $this->phasePrompts($program);
        if (($options['run_mode'] ?? null) === 'app_dry_run') {
            $phasePrompts = $phasePrompts->take(1)->values();
        }

        $runner = isset($options['runner_agent_id'])
            ? MiriamRunnerAgent::find($options['runner_agent_id'])
            : null;
        $runner ??= MiriamRunnerAgent::query()
            ->where('status', 'active')
            ->orderByDesc('last_seen_at')
            ->orderBy('id')
            ->first();

        $job = MiriamDevelopmentJob::create([
            'prompt_program_id' => $program->id,
            'managed_app_id' => $options['managed_app_id'] ?? null,
            'validation_profile_id' => $options['validation_profile_id'] ?? null,
            'runner_agent_id' => $runner?->id,
            'started_by_user_id' => $user?->id,
            'title' => ($options['app_context']['managed_app_name'] ?? null)
                ? 'Development job for '.$options['app_context']['managed_app_name']
                : 'Development job for '.$program->name,
            'status' => $runner ? 'queued' : 'waiting_for_runner',
            'current_phase_id' => $phasePrompts->first()?->phase?->id,
            'total_phases' => $phasePrompts->count(),
            'completed_phases' => 0,
            'run_mode' => $options['run_mode'] ?? 'all_phases',
            'local_project_path_snapshot' => $options['local_project_path_snapshot'] ?? null,
            'local_url_snapshot' => $options['local_url_snapshot'] ?? null,
            'options_json' => json_encode($options, JSON_PRETTY_PRINT),
        ]);

        $phasePrompts->values()->each(function (MiriamSavedPrompt $prompt, int $index) use ($job, $program, $runner, $options): void {
            MiriamDevelopmentPhaseRun::create([
                'development_job_id' => $job->id,
                'managed_app_id' => $options['managed_app_id'] ?? null,
                'validation_profile_id' => $options['validation_profile_id'] ?? null,
                'prompt_program_id' => $program->id,
                'prompt_phase_id' => $prompt->prompt_phase_id,
                'saved_prompt_id' => $prompt->id,
                'runner_agent_id' => $runner?->id,
                'phase_order' => $index + 1,
                'status' => $runner && $index === 0 ? 'assigned' : 'queued',
                'prompt_body' => $this->promptQueue->renderPrompt($prompt),
                'runner_instruction_json' => json_encode($this->runnerInstruction($prompt), JSON_PRETTY_PRINT),
                'local_project_path_snapshot' => $options['local_project_path_snapshot'] ?? null,
                'local_url_snapshot' => $options['local_url_snapshot'] ?? null,
            ]);
        });

        $this->recordEvent(
            $job,
            'job_created',
            $runner ? 'Development job created and assigned' : 'Development job created and waiting for runner',
            $runner ? "Assigned to runner {$runner->name}." : 'No active runner is available yet.',
            $runner,
            ['total_phases' => $phasePrompts->count()]
        );
        $this->ledger->recordJob(
            $job->fresh(['managedApp', 'program', 'currentPhase', 'releasePackages']),
            'planned',
            $runner ? 'Development job created and assigned to runner.' : 'Development job created and waiting for runner.',
            null,
            ['next_action' => $runner ? 'Let the runner pick up the assigned phase.' : 'Start or check the registered runner.']
        );

        return $job->fresh(['runnerAgent', 'phaseRuns.phase', 'events']);
    }

    public function assignNextJobToRunner(MiriamRunnerAgent $runner, ?string $mode = null): ?MiriamDevelopmentJob
    {
        $runModes = $this->runnerModeRunModes($mode);

        $activeJob = MiriamDevelopmentJob::query()
            ->where('runner_agent_id', $runner->id)
            ->whereIn('status', ['queued', 'running'])
            ->when($runModes !== null, fn ($query) => $query->whereIn('run_mode', $runModes))
            ->whereHas('phaseRuns', fn ($query) => $query->whereIn('status', ['assigned', 'queued']))
            ->orderBy('created_at')
            ->first();

        if ($activeJob && ! $this->jobHasBlockingCondition($activeJob)) {
            $this->assignNextPhaseRun($activeJob->fresh(['phaseRuns']), $runner);

            return $activeJob->fresh(['program', 'runnerAgent', 'phaseRuns.phase', 'phaseRuns.savedPrompt']);
        }

        $job = MiriamDevelopmentJob::query()
            ->whereIn('status', ['queued', 'waiting_for_runner'])
            ->when($runModes !== null, fn ($query) => $query->whereIn('run_mode', $runModes))
            ->where(function ($query) use ($runner): void {
                $query->whereNull('runner_agent_id')
                    ->orWhere('runner_agent_id', $runner->id);
            })
            ->orderBy('created_at')
            ->first();

        if (! $job) {
            return null;
        }

        if ((int) $job->runner_agent_id !== $runner->id) {
            $job->update([
                'runner_agent_id' => $runner->id,
                'status' => 'queued',
            ]);

            $job->phaseRuns()->whereNull('runner_agent_id')->update([
                'runner_agent_id' => $runner->id,
            ]);

            $this->recordEvent($job->fresh(), 'job_assigned', 'Development job assigned to runner', "Assigned to {$runner->name}.", $runner);
        }

        $this->assignNextPhaseRun($job->fresh(['phaseRuns']), $runner);

        return $job->fresh(['program', 'runnerAgent', 'phaseRuns.phase', 'phaseRuns.savedPrompt']);
    }

    public function runnerModeRunModes(?string $mode): ?array
    {
        return match ($mode) {
            'app_dry_run' => ['app_dry_run'],
            'validation_only' => ['validation_only'],
            default => null,
        };
    }

    public function markJobStarted(MiriamDevelopmentJob $job, MiriamRunnerAgent $runner): MiriamDevelopmentJob
    {
        $this->ensureRunnerOwnsJob($job, $runner);

        $job->update([
            'status' => 'running',
            'started_at' => $job->started_at ?? now(),
        ]);

        $this->recordEvent($job, 'job_started', 'Runner started development job', "Runner {$runner->name} started the job.", $runner);
        $this->ledger->recordJob($job->fresh(['managedApp', 'program', 'currentPhase', 'releasePackages']), 'running', 'Runner started development job.');

        return $job->fresh();
    }

    public function markPhaseStarted(MiriamDevelopmentJob $job, MiriamDevelopmentPhaseRun $phaseRun, MiriamRunnerAgent $runner): MiriamDevelopmentPhaseRun
    {
        $this->ensureRunnerOwnsPhase($job, $phaseRun, $runner);

        $phaseRun->update([
            'status' => 'running',
            'started_at' => $phaseRun->started_at ?? now(),
        ]);

        $job->update([
            'status' => 'running',
            'current_phase_id' => $phaseRun->prompt_phase_id,
            'started_at' => $job->started_at ?? now(),
        ]);

        $this->recordEvent($job, 'phase_started', 'Runner started phase', $phaseRun->phase?->title, $runner, ['phase_run_id' => $phaseRun->id], $phaseRun);
        $this->ledger->recordJob($job->fresh(['managedApp', 'program', 'currentPhase', 'releasePackages']), 'running', 'Runner started phase '.($phaseRun->phase?->title ?: 'untitled').'.', $phaseRun);

        return $phaseRun->fresh(['phase', 'savedPrompt']);
    }

    public function submitPhaseResult(MiriamDevelopmentJob $job, MiriamDevelopmentPhaseRun $phaseRun, MiriamRunnerAgent $runner, array $data): MiriamDevelopmentPhaseRun
    {
        $this->ensureRunnerOwnsPhase($job, $phaseRun, $runner);

        $stdout = (string) ($data['codex_stdout'] ?? '');
        $stderr = (string) ($data['codex_stderr'] ?? '');
        $isDryRun = ($data['status'] ?? null) === 'dry_run_passed'
            || (bool) data_get($data, 'parsed_result_json.dry_run', false)
            || (bool) data_get($data, 'validation_json.runner_dry_run', false);
        $isOnePhaseExecution = ($data['status'] ?? null) === 'one_phase_executed'
            || (bool) data_get($data, 'parsed_result_json.one_phase_execution', false);
        $isMultiPhaseExecution = ($data['status'] ?? null) === 'multi_phase_executed'
            || (bool) data_get($data, 'parsed_result_json.multi_phase_execution', false);
        $isAppDryRun = ($data['status'] ?? null) === 'app_dry_run_passed'
            || (bool) data_get($data, 'parsed_result_json.app_dry_run', false);
        $isValidationOnly = in_array(($data['status'] ?? null), ['validation_only_passed', 'validation_only_failed'], true)
            || (bool) data_get($data, 'parsed_result_json.validation_only', false);
        $parsed = $this->parsedResultForPhase($stdout, $data, $isDryRun);
        $status = ($isDryRun || $isOnePhaseExecution || $isValidationOnly) ? 'output_received' : $this->phaseStatusFromParsed($parsed);
        if ($isAppDryRun) {
            $status = $this->validationFailed($data['validation_json'] ?? []) ? 'failed' : 'passed';
        }
        if ($isValidationOnly) {
            $status = $this->validationFailed($data['validation_json'] ?? []) ? 'failed' : 'passed';
        }

        $validation = $data['validation_json'] ?? ($parsed['validation'] ?? []);
        $filesChanged = $data['files_changed_json'] ?? ($parsed['files_changed'] ?? []);
        $backupPaths = $data['backup_paths_json'] ?? ($data['backup_paths'] ?? null);
        $manifestBefore = $data['manifest_before_json'] ?? ($data['manifest_before'] ?? null);
        $manifestAfter = $data['manifest_after_json'] ?? ($data['manifest_after'] ?? null);
        $scannerStatus = data_get($parsed, 'safety_scanner', 'unknown');
        $validationFailed = $this->validationFailed($validation);
        $hasBlockers = ! empty($parsed['blockers'] ?? []);
        $parserUnclear = ($parsed['status'] ?? 'review_required') === 'review_required';

        if ($isValidationOnly && $this->validationFailed($validation)) {
            $status = 'failed';
        }

        if ($isMultiPhaseExecution) {
            $status = $this->multiPhaseCanPass($parsed, $validation, $scannerStatus) ? 'passed' : $this->multiPhaseFailureStatus($parsed, $validation, $scannerStatus);
        }

        $phaseRun->update([
            'status' => $status,
            'codex_stdout' => $stdout ?: null,
            'codex_stderr' => $stderr ?: null,
            'parsed_result_json' => json_encode($parsed, JSON_PRETTY_PRINT),
            'validation_json' => json_encode($validation, JSON_PRETTY_PRINT),
            'files_changed_json' => json_encode($filesChanged, JSON_PRETTY_PRINT),
            'backup_paths_json' => $backupPaths !== null ? json_encode($backupPaths, JSON_PRETTY_PRINT) : $phaseRun->backup_paths_json,
            'manifest_before_json' => $manifestBefore !== null ? json_encode($manifestBefore, JSON_PRETTY_PRINT) : $phaseRun->manifest_before_json,
            'manifest_after_json' => $manifestAfter !== null ? json_encode($manifestAfter, JSON_PRETTY_PRINT) : $phaseRun->manifest_after_json,
            'release_package_path' => $data['release_package_path'] ?? $phaseRun->release_package_path,
            'completed_at' => now(),
            'error_message' => $status === 'failed' ? ($data['error_message'] ?? 'Phase failed validation.') : null,
        ]);

        $freshJob = $job->fresh(['phaseRuns']);

        if ($isAppDryRun) {
            $freshJob->phaseRuns()
                ->where('id', '!=', $phaseRun->id)
                ->whereIn('status', ['queued', 'assigned'])
                ->update([
                    'status' => 'skipped',
                    'completed_at' => now(),
                    'error_message' => 'Skipped after app dry-run completed; app dry-run requires only one context verification phase.',
                ]);

            $freshJob->update([
                'status' => $status === 'passed' ? 'completed' : 'failed',
                'completed_phases' => $status === 'passed' ? 1 : 0,
                'completed_at' => $status === 'passed' ? now() : null,
                'failed_phase_id' => null,
                'error_message' => $status === 'passed'
                    ? 'App dry-run completed. Runner verified app context and stopped before Codex and validation.'
                    : ($data['error_message'] ?? 'App dry-run failed.'),
            ]);
            $this->recordEvent(
                $freshJob,
                'app_dry_run_result_received',
                $status === 'passed' ? 'App dry-run completed' : 'App dry-run failed',
                $data['message'] ?? 'Runner validated the app context and stopped before Codex execution.',
                $runner,
                [
                    'status' => $status,
                    'app_dry_run' => true,
                    'validation' => $validation,
                    'job_completed' => $status === 'passed',
                ],
                $phaseRun
            );
        } elseif ($isValidationOnly) {
            $freshJob->update([
                'status' => $validationFailed ? 'waiting_for_manual_fix' : 'completed',
                'completed_phases' => $validationFailed ? $freshJob->completed_phases : 1,
                'completed_at' => $validationFailed ? null : now(),
                'failed_phase_id' => $validationFailed ? $phaseRun->prompt_phase_id : null,
                'error_message' => $validationFailed ? ($data['error_message'] ?? 'Validation-only run failed.') : 'Validation-only run passed.',
            ]);
            $this->recordEvent(
                $freshJob,
                'app_validation_only_result_received',
                'App validation-only result received',
                $data['message'] ?? 'Runner executed validation commands only and stopped.',
                $runner,
                [
                    'status' => $status,
                    'validation_only' => true,
                    'validation' => $validation,
                    'validation_failed' => $validationFailed,
                ],
                $phaseRun
            );

            if ($validationFailed) {
                app(DevelopmentFailureRecoveryService::class)->createFailureFromPhaseRun($phaseRun->fresh(['job', 'phase']), $runner);
            }
        } elseif ($isDryRun) {
            $freshJob->update([
                'status' => 'waiting_for_manual_fix',
                'failed_phase_id' => $phaseRun->prompt_phase_id,
                'error_message' => 'Local runner dry-run completed. Real Codex execution is paused until Phase 3F.',
            ]);
            $this->recordEvent(
                $freshJob,
                'local_runner_dry_run_received',
                'Local runner dry-run received',
                $data['message'] ?? 'Local runner dry-run reached this phase. Codex execution was intentionally skipped.',
                $runner,
                ['status' => $status, 'dry_run' => true],
                $phaseRun
            );
            app(MiriamDevelopmentApprovalNotifier::class)->notifyJobNeedsAttention($freshJob->fresh(['managedApp', 'runnerAgent']));
        } elseif ($isOnePhaseExecution) {
            $freshJob->update([
                'status' => $validationFailed || $scannerStatus === 'blocked' ? 'waiting_for_manual_fix' : 'waiting_for_approval',
                'failed_phase_id' => $validationFailed || $scannerStatus === 'blocked' ? $phaseRun->prompt_phase_id : null,
                'error_message' => $data['error_message'] ?? 'One-phase Codex execution completed and is paused for review.',
            ]);
            $this->recordEvent(
                $freshJob,
                'one_phase_codex_execution_result_received',
                'One-phase Codex execution result received',
                $data['message'] ?? 'One-phase Codex execution completed and stopped for review.',
                $runner,
                [
                    'status' => $status,
                    'one_phase_execution' => true,
                    'validation' => $validation,
                    'changed_files_count' => count($filesChanged),
                    'safety_scanner' => $scannerStatus,
                ],
                $phaseRun
            );

            if ($validationFailed || $scannerStatus === 'blocked' || in_array(($data['status'] ?? null), ['failed', 'blocked'], true)) {
                app(DevelopmentFailureRecoveryService::class)->createFailureFromPhaseRun($phaseRun->fresh(['job', 'phase']), $runner);
            } else {
                app(MiriamDevelopmentApprovalNotifier::class)->notifyJobNeedsAttention(
                    $freshJob->fresh(['managedApp', 'runnerAgent']),
                    'One-phase Codex execution completed and needs review before continuing.'
                );
            }
        } elseif ($isMultiPhaseExecution) {
            $freshPhaseRun = $phaseRun->fresh();
            $freshJob = $freshJob->fresh(['phaseRuns']);

            if ($status === 'passed') {
                $this->progressControlledMultiPhase($freshJob, $freshPhaseRun, $runner, [
                    'validation' => $validation,
                    'changed_files_count' => count($filesChanged),
                    'safety_scanner' => $scannerStatus,
                    'parser_status' => $parsed['status'] ?? null,
                ]);
            } else {
                $freshJob->update([
                    'status' => $status === 'blocked' ? 'blocked' : 'waiting_for_manual_fix',
                    'failed_phase_id' => $phaseRun->prompt_phase_id,
                    'error_message' => $data['error_message']
                        ?? ($parserUnclear ? 'Parser result was unclear.' : ($hasBlockers ? 'Codex reported blockers.' : 'Controlled multi-phase gate failed.')),
                ]);
                $this->recordEvent(
                    $freshJob,
                    'multi_phase_gate_blocked',
                    'Controlled multi-phase gate blocked progression',
                    $freshJob->error_message,
                    $runner,
                    [
                        'status' => $status,
                        'validation_failed' => $validationFailed,
                        'has_blockers' => $hasBlockers,
                        'parser_unclear' => $parserUnclear,
                        'safety_scanner' => $scannerStatus,
                    ],
                    $freshPhaseRun
                );
                app(DevelopmentFailureRecoveryService::class)->createFailureFromPhaseRun($freshPhaseRun->fresh(['job', 'phase']), $runner);
            }
        } else {
            $this->syncJobStatusFromPhase($freshJob, $phaseRun->fresh());
            $this->recordEvent($job->fresh(), 'phase_result_received', 'Phase result received', "Phase run #{$phaseRun->id} is {$status}.", $runner, ['status' => $status], $phaseRun);

            if (in_array($status, ['failed', 'blocked', 'waiting_for_manual_fix'], true)) {
                app(DevelopmentFailureRecoveryService::class)->createFailureFromPhaseRun($phaseRun->fresh(['job', 'phase']), $runner);
            }
        }

        $this->recordLedgerAndMaybeNotify($job->fresh(['managedApp', 'program', 'currentPhase', 'releasePackages']), $phaseRun->fresh(['phase']));

        return $phaseRun->fresh(['phase', 'savedPrompt']);
    }

    public function failJob(MiriamDevelopmentJob $job, MiriamRunnerAgent $runner, ?string $message = null): MiriamDevelopmentJob
    {
        $this->ensureRunnerOwnsJob($job, $runner);

        $job->update([
            'status' => 'failed',
            'error_message' => $message,
        ]);

        $this->recordEvent($job, 'job_failed', 'Development job failed', $message, $runner);
        $this->ledger->recordJob($job->fresh(['managedApp', 'program', 'currentPhase', 'releasePackages']), 'failed', $message ?: 'Development job failed.');

        return $job->fresh();
    }

    public function completeJob(MiriamDevelopmentJob $job, MiriamRunnerAgent $runner): MiriamDevelopmentJob
    {
        $this->ensureRunnerOwnsJob($job, $runner);

        abort_unless(
            $job->phaseRuns()->whereNotIn('status', ['passed', 'skipped'])->doesntExist(),
            422,
            'A development job can only be completed after all phase runs pass or are skipped.'
        );

        $job->update([
            'status' => 'completed',
            'completed_phases' => $job->phaseRuns()->where('status', 'passed')->count(),
            'completed_at' => now(),
        ]);

        $this->recordEvent($job, 'job_completed', 'Development job completed', 'All runnable phases are passed or skipped.', $runner);
        $this->ledger->recordJob($job->fresh(['managedApp', 'program', 'currentPhase', 'releasePackages']), 'completed', 'All runnable phases are passed or skipped.');
        $this->sendDevelopmentSummary($job->fresh(['managedApp', 'program', 'currentPhase', 'releasePackages']));

        return $job->fresh();
    }

    public function cancelQueuedJob(MiriamDevelopmentJob $job, ?User $user = null): MiriamDevelopmentJob
    {
        abort_unless(in_array($job->status, ['queued', 'waiting_for_runner', 'paused'], true), 422, 'Only queued, paused, or waiting jobs can be cancelled in this phase.');

        $job->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        $this->recordEvent($job, 'job_cancelled', 'Development job cancelled', $user ? "Cancelled by {$user->name}." : 'Cancelled from Slack/app.');

        return $job->fresh();
    }

    public function pauseJob(MiriamDevelopmentJob $job, ?User $user = null): MiriamDevelopmentJob
    {
        abort_unless(in_array($job->status, ['queued', 'waiting_for_runner', 'running'], true), 422, 'Only queued, waiting, or running jobs can be paused.');

        $job->update(['status' => 'paused']);
        $this->recordEvent($job, 'job_paused', 'Development job paused', $user ? "Paused by {$user->name}." : 'Paused from Slack/app.');

        return $job->fresh();
    }

    public function resumeJob(MiriamDevelopmentJob $job, ?User $user = null): MiriamDevelopmentJob
    {
        abort_unless($job->status === 'paused', 422, 'Only paused jobs can be resumed.');
        abort_unless(! $this->jobHasUnresolvedFailure($job), 422, 'Job has an unresolved failure or approval gate.');

        $runner = $job->runnerAgent;
        $next = $this->nextRunnablePhaseRun($job->fresh(['phaseRuns']));

        $job->update([
            'status' => $runner ? 'queued' : 'waiting_for_runner',
            'current_phase_id' => $next?->prompt_phase_id,
        ]);

        if ($runner && $next) {
            $next->update([
                'runner_agent_id' => $runner->id,
                'status' => 'assigned',
            ]);
        }

        $this->recordEvent($job->fresh(), 'job_resumed', 'Development job resumed', $user ? "Resumed by {$user->name}." : 'Resumed from Slack/app.', $runner);

        return $job->fresh();
    }

    public function approveWaitingJob(MiriamDevelopmentJob $job, ?User $user = null): MiriamDevelopmentJob
    {
        $job->loadMissing(['phaseRuns', 'runnerAgent']);

        abort_unless($job->status === 'waiting_for_approval', 422, 'Only jobs waiting for approval can be approved.');
        abort_unless(! $this->jobHasUnresolvedFailure($job), 422, 'Job has an unresolved failure. Resolve validation/manual fix first.');

        $phaseRuns = $job->phaseRuns()->get();
        $approvalPhaseRuns = $phaseRuns->whereIn('status', ['waiting_for_approval', 'output_received']);
        $phaseToApprove = $approvalPhaseRuns->sortByDesc('updated_at')->first();

        abort_unless($phaseToApprove, 422, 'No phase run is waiting for approval.');
        abort_unless(
            ! $this->validationFailed($phaseToApprove->validation()) || $this->phaseHasResolvedFailure($phaseToApprove),
            422,
            'Cannot approve because validation did not pass.'
        );

        $phaseToApprove->update([
            'status' => 'passed',
            'completed_at' => $phaseToApprove->completed_at ?? now(),
            'error_message' => null,
        ]);

        $completed = $job->phaseRuns()->where('status', 'passed')->count();
        $remaining = $job->phaseRuns()->whereNotIn('status', ['passed', 'skipped'])->exists();

        if (! $remaining) {
            $job->update([
                'status' => 'completed',
                'completed_phases' => $completed,
                'completed_at' => now(),
                'failed_phase_id' => null,
                'error_message' => 'Approved and completed from Slack/app approval gate.',
            ]);
        } elseif ($job->run_mode === 'controlled_multi_phase') {
            $job->update([
                'status' => 'paused',
                'completed_phases' => $completed,
                'failed_phase_id' => null,
                'error_message' => 'Approved from Slack/app. Controlled multi-phase continuation remains paused until explicitly resumed.',
            ]);
        } else {
            abort(422, 'This job still has remaining phases and cannot be completed by the approval gate.');
        }

        $this->recordEvent(
            $job->fresh(),
            'approval_gate_approved',
            'Development approval gate approved',
            $user ? "Approved by {$user->name}." : 'Approved from Slack/app.',
            $job->runnerAgent,
            ['phase_run_id' => $phaseToApprove->id, 'run_mode' => $job->run_mode],
            $phaseToApprove
        );

        return $job->fresh(['phaseRuns', 'runnerAgent']);
    }

    public function recordEvent(
        MiriamDevelopmentJob $job,
        string $type,
        string $title,
        ?string $body = null,
        ?MiriamRunnerAgent $runner = null,
        array $meta = [],
        ?MiriamDevelopmentPhaseRun $phaseRun = null,
    ): MiriamDevelopmentJobEvent {
        return MiriamDevelopmentJobEvent::create([
            'development_job_id' => $job->id,
            'phase_run_id' => $phaseRun?->id,
            'runner_agent_id' => $runner?->id,
            'event_type' => $type,
            'title' => $title,
            'body' => $body,
            'meta_json' => $meta === [] ? null : json_encode($meta, JSON_PRETTY_PRINT),
        ]);
    }

    public function statusSummary(): array
    {
        $latest = MiriamDevelopmentJob::query()
            ->with(['currentPhase', 'runnerAgent', 'events' => fn ($query) => $query->latest()->limit(1)])
            ->latest()
            ->first();

        return [
            'active_runner_count' => MiriamRunnerAgent::where('status', 'active')->count(),
            'latest_runner' => MiriamRunnerAgent::query()->latest('last_seen_at')->first(),
            'latest_job' => $latest,
            'last_event' => $latest?->events->first(),
            'latest_dry_run_event' => MiriamDevelopmentJobEvent::query()
                ->where('event_type', 'local_runner_dry_run_received')
                ->latest()
                ->first(),
            'latest_one_phase_event' => MiriamDevelopmentJobEvent::query()
                ->where('event_type', 'one_phase_codex_execution_result_received')
                ->latest()
                ->first(),
            'latest_multi_phase_event' => MiriamDevelopmentJobEvent::query()
                ->whereIn('event_type', ['multi_phase_phase_passed', 'multi_phase_gate_blocked', 'multi_phase_job_completed'])
                ->latest()
                ->first(),
            'latest_app_dry_run_event' => MiriamDevelopmentJobEvent::query()
                ->where('event_type', 'app_dry_run_result_received')
                ->latest()
                ->first(),
            'latest_app_validation_event' => MiriamDevelopmentJobEvent::query()
                ->where('event_type', 'app_validation_only_result_received')
                ->latest()
                ->first(),
            'active_failure_count' => MiriamDevelopmentFailure::query()
                ->whereIn('status', ['open', 'fix_requested', 'fixing', 'manual_attention_required', 'failed'])
                ->whereHas('job', fn ($query) => $query->where(fn ($inner) => $inner->whereNull('run_mode')->orWhere('run_mode', '!=', 'slack_callback_test')))
                ->count(),
            'latest_failure' => MiriamDevelopmentFailure::query()
                ->with(['job', 'phaseRun.phase'])
                ->latest()
                ->first(),
        ];
    }

    private function recordLedgerAndMaybeNotify(MiriamDevelopmentJob $job, MiriamDevelopmentPhaseRun $phaseRun): void
    {
        $ledger = $this->ledger->recordPhaseResult($job, $phaseRun);

        if (in_array($job->status, ['completed', 'waiting_for_approval'], true)) {
            $this->sendDevelopmentSummary($job);
        }

        if (in_array($job->status, ['blocked', 'failed'], true)) {
            app(MiriamSmartSlackNotificationService::class)->notifyDevelopmentBlocked(
                $job->managedApp?->name ?: ($job->program?->name ?: 'Miriam'),
                $phaseRun->phase?->title ?: ($ledger->phase_name ?: 'current phase'),
                $job->error_message ?: 'Development job is blocked or failed.',
                $job->id
            );
        }
    }

    private function sendDevelopmentSummary(MiriamDevelopmentJob $job): void
    {
        $appName = $job->managedApp?->name ?: ($job->program?->name ?: 'Miriam');

        app(MiriamSmartSlackNotificationService::class)->notifyDevelopmentSummary(
            $appName,
            $this->ledger->developmentSummaryText($job->managedApp?->slug),
            $job->id,
            $job->status
        );
    }

    private function parsedResultForPhase(string $stdout, array $data, bool $isDryRun): array
    {
        if (isset($data['parsed_result_json']) && is_array($data['parsed_result_json'])) {
            return array_merge([
                'status' => 'review_required',
                'validation' => [],
                'files_changed' => [],
                'blockers' => [],
            ], $data['parsed_result_json']);
        }

        if ($stdout !== '') {
            return $this->parser->parse($stdout);
        }

        return [
            'status' => 'review_required',
            'summary' => $isDryRun
                ? 'Local runner dry-run reached this phase. Codex execution was intentionally skipped.'
                : 'No Codex output was supplied.',
            'validation' => $data['validation_json'] ?? [],
            'files_changed' => $data['files_changed_json'] ?? [],
            'blockers' => [],
            'dry_run' => $isDryRun,
        ];
    }

    private function phasePrompts(MiriamPromptProgram $program): Collection
    {
        $phases = $program->phases()
            ->whereIn('status', ['ready', 'queued', 'in_progress'])
            ->orderBy('sort_order')
            ->get();

        return $phases
            ->map(fn (MiriamPromptPhase $phase) => $this->promptQueue->nextSavedPrompt($program, $phase))
            ->filter()
            ->values();
    }

    private function runnerInstruction(MiriamSavedPrompt $prompt): array
    {
        return [
            'mode' => 'manual_codex_prompt',
            'phase_key' => $prompt->phase?->phase_key,
            'phase_title' => $prompt->phase?->title,
            'saved_prompt_id' => $prompt->id,
            'do_not_run_shell_commands_from_cloud' => true,
            'do_not_use_git_as_primary_workflow' => true,
            'do_not_auto_deploy' => true,
            'human_review_required_for_risky_actions' => true,
        ];
    }

    private function phaseStatusFromParsed(array $parsed): string
    {
        return match ($parsed['status'] ?? 'review_required') {
            'passed' => 'passed',
            'failed' => 'failed',
            'blocked' => 'blocked',
            default => 'waiting_for_manual_fix',
        };
    }

    private function progressControlledMultiPhase(MiriamDevelopmentJob $job, MiriamDevelopmentPhaseRun $phaseRun, MiriamRunnerAgent $runner, array $meta): void
    {
        $completed = $job->phaseRuns()->where('status', 'passed')->count();
        $next = $this->nextRunnablePhaseRun($job->fresh(['phaseRuns']));

        if (! $next) {
            $job->update([
                'status' => 'completed',
                'completed_phases' => $completed,
                'completed_at' => now(),
                'current_phase_id' => $phaseRun->prompt_phase_id,
                'failed_phase_id' => null,
                'error_message' => null,
            ]);
            $this->recordEvent($job->fresh(), 'multi_phase_job_completed', 'Controlled multi-phase job completed', 'All phase runs passed.', $runner, $meta, $phaseRun);

            return;
        }

        if ($this->jobHasBlockingCondition($job)) {
            $job->update([
                'status' => 'waiting_for_manual_fix',
                'completed_phases' => $completed,
                'failed_phase_id' => $phaseRun->prompt_phase_id,
                'error_message' => 'Controlled multi-phase progression is blocked by an unresolved failure or approval gate.',
            ]);
            $this->recordEvent($job->fresh(), 'multi_phase_gate_blocked', 'Controlled multi-phase gate blocked progression', $job->error_message, $runner, $meta, $phaseRun);

            return;
        }

        $next->update([
            'runner_agent_id' => $runner->id,
            'status' => 'assigned',
        ]);

        $job->update([
            'status' => 'running',
            'completed_phases' => $completed,
            'current_phase_id' => $next->prompt_phase_id,
            'failed_phase_id' => null,
            'error_message' => null,
        ]);

        $this->recordEvent($job->fresh(), 'multi_phase_phase_passed', 'Controlled multi-phase phase passed', "Next phase run #{$next->id} assigned.", $runner, $meta, $phaseRun);
    }

    private function assignNextPhaseRun(MiriamDevelopmentJob $job, MiriamRunnerAgent $runner): ?MiriamDevelopmentPhaseRun
    {
        if ($this->jobHasBlockingCondition($job)) {
            return null;
        }

        $next = $this->nextRunnablePhaseRun($job);

        if (! $next) {
            return null;
        }

        if ($next->status === 'queued' || (int) $next->runner_agent_id !== (int) $runner->id) {
            $next->update([
                'runner_agent_id' => $runner->id,
                'status' => 'assigned',
            ]);
        }

        return $next->fresh(['phase', 'savedPrompt']);
    }

    private function nextRunnablePhaseRun(MiriamDevelopmentJob $job): ?MiriamDevelopmentPhaseRun
    {
        return $job->phaseRuns()
            ->whereIn('status', ['queued', 'assigned'])
            ->orderBy('phase_order')
            ->first();
    }

    private function jobHasBlockingCondition(MiriamDevelopmentJob $job): bool
    {
        if (in_array($job->status, ['waiting_for_approval', 'waiting_for_manual_fix', 'blocked', 'failed', 'cancelled', 'paused'], true)) {
            return true;
        }

        return $job->failures()
            ->whereIn('status', ['open', 'fix_requested', 'fixing', 'manual_attention_required', 'failed'])
            ->whereHas('job', fn ($query) => $query->where(fn ($inner) => $inner->whereNull('run_mode')->orWhere('run_mode', '!=', 'slack_callback_test')))
            ->exists();
    }

    private function jobHasUnresolvedFailure(MiriamDevelopmentJob $job): bool
    {
        return $job->failures()
            ->whereIn('status', ['open', 'fix_requested', 'fixing', 'manual_attention_required', 'failed'])
            ->whereHas('job', fn ($query) => $query->where(fn ($inner) => $inner->whereNull('run_mode')->orWhere('run_mode', '!=', 'slack_callback_test')))
            ->exists();
    }

    private function phaseHasResolvedFailure(MiriamDevelopmentPhaseRun $phaseRun): bool
    {
        return $phaseRun->failures()
            ->whereIn('status', ['fixed', 'manually_fixed', 'rolled_back'])
            ->exists();
    }

    private function multiPhaseCanPass(array $parsed, array $validation, string $scannerStatus): bool
    {
        return ($parsed['status'] ?? null) === 'passed'
            && ! $this->validationFailed($validation)
            && $scannerStatus !== 'blocked'
            && empty($parsed['blockers'] ?? []);
    }

    private function multiPhaseFailureStatus(array $parsed, array $validation, string $scannerStatus): string
    {
        if (($parsed['status'] ?? null) === 'blocked' || $scannerStatus === 'blocked' || ! empty($parsed['blockers'] ?? [])) {
            return 'blocked';
        }

        if (($parsed['status'] ?? null) === 'failed' || $this->validationFailed($validation)) {
            return 'failed';
        }

        return 'waiting_for_manual_fix';
    }

    private function validationFailed(array $validation): bool
    {
        return collect($validation)->contains(fn ($value) => str_contains(strtolower((string) $value), 'fail'));
    }

    private function syncJobStatusFromPhase(MiriamDevelopmentJob $job, MiriamDevelopmentPhaseRun $phaseRun): void
    {
        $completed = $job->phaseRuns()->where('status', 'passed')->count();
        $remaining = $job->phaseRuns()->whereNotIn('status', ['passed', 'skipped'])->count();
        $updates = ['completed_phases' => $completed];

        if ($phaseRun->status === 'failed') {
            $updates['status'] = 'failed';
            $updates['failed_phase_id'] = $phaseRun->prompt_phase_id;
            $updates['error_message'] = $phaseRun->error_message;
        } elseif ($phaseRun->status === 'blocked') {
            $updates['status'] = 'blocked';
            $updates['failed_phase_id'] = $phaseRun->prompt_phase_id;
        } elseif ($phaseRun->status === 'waiting_for_manual_fix') {
            $updates['status'] = 'waiting_for_manual_fix';
            $updates['failed_phase_id'] = $phaseRun->prompt_phase_id;
        } elseif ($remaining === 0) {
            $updates['status'] = 'completed';
            $updates['completed_at'] = now();
        } else {
            $updates['status'] = 'running';
        }

        $job->update($updates);
    }

    private function ensureRunnerOwnsJob(MiriamDevelopmentJob $job, MiriamRunnerAgent $runner): void
    {
        abort_unless((int) $job->runner_agent_id === (int) $runner->id, 403, 'Runner cannot access this job.');
    }

    private function ensureRunnerOwnsPhase(MiriamDevelopmentJob $job, MiriamDevelopmentPhaseRun $phaseRun, MiriamRunnerAgent $runner): void
    {
        $this->ensureRunnerOwnsJob($job, $runner);
        abort_unless((int) $phaseRun->development_job_id === (int) $job->id, 404, 'Phase run does not belong to this job.');
        abort_unless((int) $phaseRun->runner_agent_id === (int) $runner->id, 403, 'Runner cannot access this phase run.');
    }
}
