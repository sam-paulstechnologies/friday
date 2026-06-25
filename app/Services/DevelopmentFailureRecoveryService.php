<?php

namespace App\Services;

use App\Models\MiriamDevelopmentFailure;
use App\Models\MiriamDevelopmentFixAttempt;
use App\Models\MiriamDevelopmentJob;
use App\Models\MiriamDevelopmentJobEvent;
use App\Models\MiriamDevelopmentPhaseRun;
use App\Models\MiriamRunnerAgent;

class DevelopmentFailureRecoveryService
{
    public function __construct(
        private readonly DevelopmentFailureClassifierService $classifier,
        private readonly DevelopmentFixPromptBuilderService $fixPromptBuilder,
    ) {
    }

    public function createFailureFromPhaseRun(MiriamDevelopmentPhaseRun $phaseRun, ?MiriamRunnerAgent $runner = null): MiriamDevelopmentFailure
    {
        $phaseRun->loadMissing(['job', 'phase']);
        $classification = $this->classifier->classify($phaseRun);

        $failure = MiriamDevelopmentFailure::query()
            ->where('phase_run_id', $phaseRun->id)
            ->whereIn('status', ['open', 'fix_requested', 'fixing', 'manual_attention_required', 'failed'])
            ->first();

        if (! $failure) {
            $failure = MiriamDevelopmentFailure::create(array_merge($classification, [
                'development_job_id' => $phaseRun->development_job_id,
                'phase_run_id' => $phaseRun->id,
                'runner_agent_id' => $runner?->id ?? $phaseRun->runner_agent_id,
                'status' => 'open',
            ]));
        } else {
            $failure->update($classification + [
                'runner_agent_id' => $runner?->id ?? $failure->runner_agent_id,
            ]);
        }

        $phaseRun->job?->update([
            'status' => 'waiting_for_manual_fix',
            'failed_phase_id' => $phaseRun->prompt_phase_id,
            'error_message' => $failure->summary ?: $failure->title,
        ]);

        if (! $phaseRun->job?->events()->where('event_type', 'development_failure_created')->where('phase_run_id', $phaseRun->id)->exists()) {
            $this->recordEvent(
                $phaseRun->job,
                'development_failure_created',
                $failure->title,
                $failure->summary,
                $runner,
                [
                    'failure_id' => $failure->id,
                    'failure_type' => $failure->failure_type,
                    'can_auto_fix' => $failure->can_auto_fix,
                    'needs_user_at_system' => $failure->needs_user_at_system,
                ],
                $phaseRun
            );
        }

        $fresh = $failure->fresh(['job.managedApp', 'phaseRun.phase', 'fixAttempts', 'runnerAgent']);
        app(MiriamDevelopmentApprovalNotifier::class)->notifyFailureNeedsAttention($fresh);

        return $fresh;
    }

    public function applyFix(MiriamDevelopmentFailure $failure): MiriamDevelopmentFixAttempt
    {
        abort_unless($failure->can_auto_fix, 422, 'This failure is not marked auto-fixable.');
        abort_if($failure->fixAttempts()->count() >= 3, 422, 'Maximum fix attempts reached.');

        $attempt = MiriamDevelopmentFixAttempt::create([
            'development_failure_id' => $failure->id,
            'development_job_id' => $failure->development_job_id,
            'phase_run_id' => $failure->phase_run_id,
            'runner_agent_id' => $failure->runner_agent_id,
            'attempt_number' => $failure->fixAttempts()->count() + 1,
            'status' => 'queued',
            'fix_prompt' => $this->fixPromptBuilder->build($failure),
        ]);

        $failure->update(['status' => 'fix_requested']);
        $failure->job?->update(['status' => 'waiting_for_manual_fix']);

        $this->recordEvent(
            $failure->job,
            'failure_fix_requested',
            'Apply Fix requested',
            "Fix attempt #{$attempt->attempt_number} is queued for failure #{$failure->id}.",
            $failure->runnerAgent,
            ['failure_id' => $failure->id, 'fix_attempt_id' => $attempt->id],
            $failure->phaseRun
        );

        return $attempt;
    }

    public function markManualAttentionRequired(MiriamDevelopmentFailure $failure): MiriamDevelopmentFailure
    {
        $failure->update([
            'status' => 'manual_attention_required',
            'needs_user_at_system' => true,
        ]);
        $failure->job?->update(['status' => 'waiting_for_manual_fix']);

        $this->recordEvent(
            $failure->job,
            'manual_fix_required',
            'Manual fix required',
            "Failure #{$failure->id} needs user attention at the system.",
            $failure->runnerAgent,
            ['failure_id' => $failure->id],
            $failure->phaseRun
        );

        $fresh = $failure->fresh(['job.managedApp', 'phaseRun.phase', 'runnerAgent']);
        app(MiriamDevelopmentApprovalNotifier::class)->notifyFailureNeedsAttention(
            $fresh,
            'Manual attention is required at the local system before Miriam can continue.'
        );

        return $fresh;
    }

    public function resumeAfterManualFix(MiriamDevelopmentFailure $failure): MiriamDevelopmentFailure
    {
        $failure->update(['status' => 'fixing']);
        $failure->job?->update(['status' => 'waiting_for_manual_fix']);

        $this->recordEvent(
            $failure->job,
            'manual_validation_requested',
            'Resume after manual fix requested',
            "Runner should validate manual fix for failure #{$failure->id}.",
            $failure->runnerAgent,
            ['failure_id' => $failure->id],
            $failure->phaseRun
        );

        $fresh = $failure->fresh(['job.managedApp', 'phaseRun.phase', 'runnerAgent']);
        app(MiriamDevelopmentApprovalNotifier::class)->notifyFailureNeedsAttention(
            $fresh,
            'Manual validation was requested. The runner should validate this failure and stop.'
        );

        return $fresh;
    }

    public function requestRollback(MiriamDevelopmentFailure $failure): MiriamDevelopmentFailure
    {
        $failure->update(['status' => 'fixing']);
        $failure->job?->update(['status' => 'waiting_for_manual_fix']);

        $this->recordEvent(
            $failure->job,
            'rollback_requested',
            'Rollback phase requested',
            "Runner should roll back phase for failure #{$failure->id} only if it can do so safely.",
            $failure->runnerAgent,
            ['failure_id' => $failure->id],
            $failure->phaseRun
        );

        return $failure->fresh();
    }

    public function stopJob(MiriamDevelopmentJob $job): MiriamDevelopmentJob
    {
        $job->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'error_message' => 'Stopped from failure recovery.',
        ]);

        $job->failures()->whereNotIn('status', ['fixed', 'rolled_back', 'stopped'])->update(['status' => 'stopped']);

        $this->recordEvent($job, 'development_job_stopped', 'Development job stopped', 'Stopped from failure recovery. No next phase will run.');

        return $job->fresh();
    }

    public function nextRunnerInstruction(MiriamDevelopmentJob $job, MiriamRunnerAgent $runner): array
    {
        $failure = $job->failures()
            ->with(['phaseRun.phase', 'fixAttempts' => fn ($query) => $query->latest()])
            ->whereIn('status', ['fix_requested', 'fixing', 'manual_attention_required', 'open'])
            ->latest()
            ->first();

        if (! $failure || (int) $failure->runner_agent_id !== (int) $runner->id) {
            return ['action' => 'none'];
        }

        $lastEvent = $job->events()
            ->whereIn('event_type', ['rollback_requested', 'manual_validation_requested'])
            ->latest()
            ->first();

        if ($job->status === 'cancelled' || $failure->status === 'stopped') {
            return ['action' => 'stop_job', 'failure_id' => $failure->id];
        }

        if ($lastEvent?->event_type === 'rollback_requested') {
            return ['action' => 'rollback_phase', 'failure_id' => $failure->id];
        }

        if ($lastEvent?->event_type === 'manual_validation_requested') {
            return ['action' => 'validate_after_manual_fix', 'failure_id' => $failure->id];
        }

        $attempt = $failure->fixAttempts()->where('status', 'queued')->oldest()->first();

        if ($attempt) {
            return [
                'action' => 'run_fix_attempt',
                'failure_id' => $failure->id,
                'fix_attempt_id' => $attempt->id,
                'fix_prompt' => $attempt->fix_prompt,
            ];
        }

        return ['action' => 'none', 'failure_id' => $failure->id];
    }

    public function recordFixResult(MiriamDevelopmentFailure $failure, MiriamRunnerAgent $runner, array $data): MiriamDevelopmentFixAttempt
    {
        abort_unless((int) $failure->runner_agent_id === (int) $runner->id, 403, 'Runner cannot update this failure.');

        $attempt = $failure->fixAttempts()->whereIn('status', ['queued', 'running', 'validation_running'])->oldest()->first()
            ?? $this->applyFix($failure);

        $passed = ($data['status'] ?? null) === 'passed' && ! $this->validationFailed($data['validation_json'] ?? []);
        $attempt->update([
            'status' => $passed ? 'passed' : (($data['status'] ?? null) === 'blocked' ? 'blocked' : 'failed'),
            'codex_stdout' => $data['codex_stdout'] ?? null,
            'codex_stderr' => $data['codex_stderr'] ?? null,
            'validation_json' => isset($data['validation_json']) ? json_encode($data['validation_json'], JSON_PRETTY_PRINT) : null,
            'files_changed_json' => isset($data['files_changed_json']) ? json_encode($data['files_changed_json'], JSON_PRETTY_PRINT) : null,
            'completed_at' => now(),
            'error_message' => $data['error_message'] ?? null,
        ]);

        $this->finishFailureValidation($failure, $passed, $runner, 'fix_attempt_result_received', $data['error_message'] ?? null);

        return $attempt->fresh();
    }

    public function recordManualValidationResult(MiriamDevelopmentFailure $failure, MiriamRunnerAgent $runner, array $data): MiriamDevelopmentFailure
    {
        abort_unless((int) $failure->runner_agent_id === (int) $runner->id, 403, 'Runner cannot update this failure.');

        $passed = ($data['status'] ?? null) === 'passed' && ! $this->validationFailed($data['validation_json'] ?? []);
        $this->finishFailureValidation($failure, $passed, $runner, 'manual_validation_result_received', $data['error_message'] ?? null, $data);

        return $failure->fresh();
    }

    public function recordRollbackResult(MiriamDevelopmentFailure $failure, MiriamRunnerAgent $runner, array $data): MiriamDevelopmentFailure
    {
        abort_unless((int) $failure->runner_agent_id === (int) $runner->id, 403, 'Runner cannot update this failure.');

        $passed = ($data['status'] ?? null) === 'rolled_back';
        $failure->update([
            'status' => $passed ? 'rolled_back' : 'failed',
            'resolved_at' => $passed ? now() : null,
        ]);
        $failure->phaseRun?->update(['status' => $passed ? 'rolled_back' : 'blocked', 'error_message' => $data['error_message'] ?? null]);
        $failure->job?->update([
            'status' => $passed ? 'waiting_for_approval' : 'waiting_for_manual_fix',
            'error_message' => $passed ? 'Phase rollback completed. Manual review required.' : ($data['error_message'] ?? 'Rollback failed.'),
        ]);

        $this->recordEvent(
            $failure->job,
            'rollback_result_received',
            $passed ? 'Rollback completed' : 'Rollback failed',
            $data['error_message'] ?? null,
            $runner,
            ['failure_id' => $failure->id, 'status' => $data['status'] ?? null],
            $failure->phaseRun
        );

        $fresh = $failure->fresh(['job.managedApp', 'phaseRun.phase', 'runnerAgent']);

        if ($passed) {
            app(MiriamDevelopmentApprovalNotifier::class)->notifyFailureNeedsAttention(
                $fresh,
                'Rollback completed. Manual review is required before continuing.'
            );
        }

        return $fresh;
    }

    private function finishFailureValidation(MiriamDevelopmentFailure $failure, bool $passed, MiriamRunnerAgent $runner, string $eventType, ?string $error = null, array $meta = []): void
    {
        $isSafetyGate = $this->isSafetyGate($failure);

        $failure->update([
            'status' => $passed ? 'fixed' : 'failed',
            'resolved_at' => $passed ? now() : null,
        ]);
        $failure->phaseRun?->update([
            'status' => $passed ? ($isSafetyGate ? 'waiting_for_approval' : 'passed') : 'waiting_for_manual_fix',
            'error_message' => $passed ? null : ($error ?: 'Failure recovery validation failed.'),
        ]);

        if ($passed && ! $isSafetyGate) {
            $this->completeNormalValidatedFailure($failure);
        } else {
            $failure->job?->update([
                'status' => $passed ? 'waiting_for_approval' : 'waiting_for_manual_fix',
                'error_message' => $passed ? 'Safety gate validation passed. Manual approval is still required before continuing.' : ($error ?: 'Failure recovery validation failed.'),
            ]);
        }

        $this->recordEvent(
            $failure->job,
            $eventType,
            $passed ? 'Failure recovery passed validation' : 'Failure recovery failed validation',
            $error,
            $runner,
            ['failure_id' => $failure->id, 'passed' => $passed] + $meta,
            $failure->phaseRun
        );

        $freshJob = $failure->job()->first();

        if ($passed && $isSafetyGate && $freshJob) {
            app(MiriamDevelopmentApprovalNotifier::class)->notifyJobNeedsAttention(
                $freshJob->fresh(['managedApp', 'runnerAgent']),
                'Safety gate validation passed. Manual approval is still required before continuing.'
            );
        } elseif (! $passed) {
            app(MiriamDevelopmentApprovalNotifier::class)->notifyFailureNeedsAttention(
                $failure->fresh(['job.managedApp', 'phaseRun.phase', 'runnerAgent']),
                $error ?: 'Failure recovery validation failed and needs attention.'
            );
        }
    }

    private function completeNormalValidatedFailure(MiriamDevelopmentFailure $failure): void
    {
        $job = $failure->job;
        $phaseRun = $failure->phaseRun;

        if (! $job || ! $phaseRun) {
            return;
        }

        $completed = $job->phaseRuns()->where('status', 'passed')->count();
        $next = $job->phaseRuns()
            ->whereIn('status', ['queued', 'assigned'])
            ->orderBy('phase_order')
            ->first();

        if ($next) {
            $next->update([
                'runner_agent_id' => $failure->runner_agent_id ?: $job->runner_agent_id,
                'status' => 'assigned',
            ]);

            $job->update([
                'status' => 'running',
                'completed_phases' => $completed,
                'current_phase_id' => $next->prompt_phase_id,
                'failed_phase_id' => null,
                'error_message' => 'Failure recovery validation passed. Normal development continues without approval.',
            ]);
        } else {
            $job->update([
                'status' => 'completed',
                'completed_phases' => $completed,
                'completed_at' => now(),
                'failed_phase_id' => null,
                'error_message' => 'Failure recovery validation passed. Normal development completed without approval.',
            ]);
        }

        app(MiriamDevelopmentLedgerService::class)->recordPhaseResult(
            $job->fresh(['managedApp', 'program', 'currentPhase', 'releasePackages']),
            $phaseRun->fresh(['phase']),
            ['summary' => 'Normal failure recovery validation passed and was stored in Miriam DB.']
        );
    }

    private function isSafetyGate(MiriamDevelopmentFailure $failure): bool
    {
        if ($failure->needs_user_at_system || $failure->severity === 'critical') {
            return true;
        }

        if (in_array($failure->failure_type, ['safety_risk', 'manual_browser_required', 'manual_credentials_needed'], true)) {
            return true;
        }

        return $this->textContainsAny(implode(' ', [
            $failure->summary,
            $failure->title,
            $failure->error_excerpt,
            $failure->job?->error_message,
        ]), [
            'destructive db',
            'destructive database',
            'production deploy',
            'deploy to production',
            '.env',
            'secret',
            'token',
            'credential',
            'delete files',
            'delete data',
            'external message',
            'email/client action',
            'payment',
            'billing',
            'manual credentials',
            'human business decision',
            'hard safety',
        ]);
    }

    private function textContainsAny(string $text, array $needles): bool
    {
        $haystack = strtolower($text);

        foreach ($needles as $needle) {
            if (str_contains($haystack, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function validationFailed(array $validation): bool
    {
        return collect($validation)->contains(fn ($value) => str_contains(strtolower((string) $value), 'fail'));
    }

    private function recordEvent(
        ?MiriamDevelopmentJob $job,
        string $type,
        string $title,
        ?string $body = null,
        ?MiriamRunnerAgent $runner = null,
        array $meta = [],
        ?MiriamDevelopmentPhaseRun $phaseRun = null,
    ): ?MiriamDevelopmentJobEvent {
        if (! $job) {
            return null;
        }

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
}
