<?php

namespace Tests\Feature;

use App\Models\MiriamDevelopmentJob;
use App\Models\MiriamDevelopmentFailure;
use App\Models\MiriamDevelopmentFixAttempt;
use App\Models\MiriamDevelopmentLedger;
use App\Models\MiriamDevelopmentPhaseRun;
use App\Models\MiriamManagedApp;
use App\Models\MiriamPromptPhase;
use App\Models\MiriamPromptProgram;
use App\Models\MiriamReleasePackage;
use App\Models\MiriamRunnerAgent;
use App\Models\MiriamSavedPrompt;
use App\Models\MiriamSlackPendingConfirmation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DevelopmentFailureClassifierService;
use App\Services\DevelopmentFailureRecoveryService;
use App\Services\MiriamDevelopmentManagerService;
use App\Services\MiriamReleasePackageService;
use App\Services\MiriamRunnerMonitoringService;
use App\Services\MiriamSmartSlackNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MiriamDevelopmentManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_runner_agent_command_stores_token_hash_not_raw_token(): void
    {
        $this->artisan('miriam:runner-agent:create', ['name' => 'Sam Laptop'])
            ->expectsOutputToContain('Miriam runner agent created.')
            ->expectsOutputToContain('Copy this token now.')
            ->assertExitCode(0);

        $runner = MiriamRunnerAgent::firstOrFail();

        $this->assertNotEmpty($runner->token_hash);
        $this->assertStringNotContainsString('mra_', $runner->token_hash);
        $this->assertSame(64, strlen($runner->token_hash));
    }

    public function test_missing_invalid_and_disabled_runner_tokens_are_rejected(): void
    {
        $token = 'mra_valid';
        $runner = $this->runner($token, ['status' => 'disabled']);

        $this->postJson('/api/runner/heartbeat')->assertUnauthorized();
        $this->withToken('wrong')->postJson('/api/runner/heartbeat')->assertUnauthorized();
        $this->withToken($token)->postJson('/api/runner/heartbeat')->assertUnauthorized();

        $this->assertNull($runner->fresh()->last_seen_at);
    }

    public function test_valid_heartbeat_updates_last_seen_and_runner_details(): void
    {
        $token = 'mra_valid';
        $runner = $this->runner($token, ['status' => 'inactive']);

        $this->withToken($token)
            ->postJson('/api/runner/heartbeat', [
                'machine_name' => 'Sam-PC',
                'os' => 'Windows',
                'capabilities' => ['dry_run' => true],
            ])
            ->assertOk()
            ->assertJsonPath('runner.status', 'active')
            ->assertJsonPath('runner.machine_name', 'Sam-PC');

        $runner->refresh();
        $this->assertNotNull($runner->last_seen_at);
        $this->assertSame('active', $runner->status);
        $this->assertSame(['dry_run' => true], $runner->capabilities());
    }

    public function test_starting_job_creates_phase_runs_and_waits_without_runner(): void
    {
        [$user] = $this->context();
        $this->promptProgram();

        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram($user);

        $this->assertSame('waiting_for_runner', $job->status);
        $this->assertSame(1, $job->total_phases);
        $this->assertDatabaseCount('miriam_development_phase_runs', 1);
        $this->assertStringContainsString('MIRIAM_RESULT_JSON:', MiriamDevelopmentPhaseRun::firstOrFail()->prompt_body);
        $this->assertDatabaseHas('miriam_development_job_events', ['event_type' => 'job_created']);
    }

    public function test_starting_job_assigns_active_runner(): void
    {
        [$user] = $this->context();
        $runner = $this->runner('mra_valid', ['status' => 'active']);
        $this->promptProgram(slug: 'monitor-dedupe-'.Str::lower(Str::random(8)));

        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram($user);

        $this->assertSame('queued', $job->status);
        $this->assertSame($runner->id, $job->runner_agent_id);
        $this->assertSame('assigned', $job->phaseRuns()->firstOrFail()->status);
    }

    public function test_valid_runner_can_fetch_next_job_and_other_runner_cannot(): void
    {
        $runnerOne = $this->runner('mra_one', ['status' => 'active']);
        $runnerTwo = $this->runner('mra_two', ['status' => 'active', 'slug' => 'runner-two', 'name' => 'Runner Two']);
        $this->promptProgram();
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();

        $this->withToken('mra_two')
            ->getJson('/api/runner/jobs/next')
            ->assertOk()
            ->assertJsonPath('has_job', false);

        $this->withToken('mra_one')
            ->getJson('/api/runner/jobs/next')
            ->assertOk()
            ->assertJsonPath('has_job', true)
            ->assertJsonPath('job.id', $job->id);

        $this->assertSame($runnerOne->id, $job->fresh()->runner_agent_id);
        $this->assertNotSame($runnerTwo->id, $job->fresh()->runner_agent_id);
    }

    public function test_runner_can_mark_job_started_phase_started_and_submit_result(): void
    {
        $this->runner('mra_valid', ['status' => 'active']);
        $this->promptProgram();
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();
        $phaseRun = $job->phaseRuns()->firstOrFail();

        $this->withToken('mra_valid')
            ->postJson("/api/runner/jobs/{$job->id}/start")
            ->assertOk()
            ->assertJsonPath('job.status', 'running');

        $this->withToken('mra_valid')
            ->postJson("/api/runner/jobs/{$job->id}/phase-runs/{$phaseRun->id}/started")
            ->assertOk()
            ->assertJsonPath('phase_run.status', 'running');

        $this->withToken('mra_valid')
            ->postJson("/api/runner/jobs/{$job->id}/phase-runs/{$phaseRun->id}/result", [
                'codex_stdout' => $this->resultJson('passed'),
            ])
            ->assertOk()
            ->assertJsonPath('phase_run.status', 'passed');

        $this->assertSame('completed', $job->fresh()->status);
    }

    public function test_dry_run_phase_result_is_accepted_without_completing_job_or_passing_phase(): void
    {
        $this->runner('mra_valid', ['status' => 'active']);
        $program = $this->promptProgram();
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();
        $phaseRun = $job->phaseRuns()->firstOrFail();
        $phase = $phaseRun->phase;

        $this->withToken('mra_valid')
            ->postJson("/api/runner/jobs/{$job->id}/phase-runs/{$phaseRun->id}/result", $this->dryRunPayload())
            ->assertOk()
            ->assertJsonPath('phase_run.status', 'output_received')
            ->assertJsonPath('phase_run.parsed_result.dry_run', true)
            ->assertJsonPath('phase_run.validation.runner_dry_run', 'passed');

        $this->assertSame('waiting_for_manual_fix', $job->fresh()->status);
        $this->assertSame('ready', $phase->fresh()->status);
        $this->assertSame(0, $job->fresh()->completed_phases);
        $this->assertDatabaseHas('miriam_development_job_events', [
            'development_job_id' => $job->id,
            'phase_run_id' => $phaseRun->id,
            'event_type' => 'local_runner_dry_run_received',
            'title' => 'Local runner dry-run received',
        ]);
        $this->assertSame('miriam-product-build', $program->slug);
    }

    public function test_one_phase_result_stores_artifacts_and_pauses_for_approval(): void
    {
        $this->runner('mra_valid', ['status' => 'active']);
        $this->promptProgram(includeNextPhase: true);
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();
        $phaseRun = $job->phaseRuns()->firstOrFail();

        $this->withToken('mra_valid')
            ->postJson("/api/runner/jobs/{$job->id}/phase-runs/{$phaseRun->id}/result", $this->onePhasePayload())
            ->assertOk()
            ->assertJsonPath('phase_run.status', 'output_received')
            ->assertJsonPath('phase_run.parsed_result.one_phase_execution', true)
            ->assertJsonPath('phase_run.validation.php_artisan_test', 'passed')
            ->assertJsonPath('phase_run.backup_paths.0', 'storage/app/backups/source.zip')
            ->assertJsonPath('phase_run.manifest_before_available', true)
            ->assertJsonPath('phase_run.manifest_after_available', true);

        $job->refresh();
        $this->assertSame('waiting_for_approval', $job->status);
        $this->assertSame(0, $job->completed_phases);
        $this->assertSame('ready', $phaseRun->phase->fresh()->status);
        $this->assertSame('queued', MiriamPromptPhase::where('phase_key', 'phase_next')->firstOrFail()->status);
        $this->assertDatabaseHas('miriam_development_job_events', [
            'development_job_id' => $job->id,
            'phase_run_id' => $phaseRun->id,
            'event_type' => 'one_phase_codex_execution_result_received',
        ]);
    }

    public function test_fake_codex_one_phase_result_is_stored_as_simulated_and_does_not_advance(): void
    {
        $this->runner('mra_valid', ['status' => 'active']);
        $this->promptProgram(includeNextPhase: true);
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();
        $phaseRun = $job->phaseRuns()->firstOrFail();

        $this->withToken('mra_valid')
            ->postJson("/api/runner/jobs/{$job->id}/phase-runs/{$phaseRun->id}/result", $this->onePhasePayload(parsed: [
                'status' => 'review_required',
                'one_phase_execution' => true,
                'fake_codex_mode' => true,
                'safety_scanner' => 'passed',
                'safety_risks' => [],
            ], validation: ['fake_codex_mode' => 'passed', 'php_artisan_test' => 'passed']))
            ->assertOk()
            ->assertJsonPath('phase_run.status', 'output_received')
            ->assertJsonPath('phase_run.parsed_result.fake_codex_mode', true)
            ->assertJsonPath('phase_run.validation.fake_codex_mode', 'passed');

        $this->assertSame('waiting_for_approval', $job->fresh()->status);
        $this->assertSame('queued', MiriamPromptPhase::where('phase_key', 'phase_next')->firstOrFail()->status);
    }

    public function test_multi_phase_disabled_by_default_in_runner_config(): void
    {
        $config = json_decode(file_get_contents(base_path('tools/miriam-runner/runner-config.example.json')), true);

        $this->assertFalse($config['multi_phase_enabled']);
        $this->assertSame(1, $config['max_phases_per_invocation']);
        $this->assertTrue($config['stop_after_one_phase']);
        $this->assertTrue($config['require_validation_between_phases']);
        $this->assertTrue($config['stop_on_failure']);
        $this->assertTrue($config['stop_on_safety_risk']);
        $this->assertTrue($config['stop_on_parser_unclear']);
        $this->assertTrue($config['stop_on_manual_approval']);
    }

    public function test_controlled_multi_phase_passed_phase_assigns_next_phase(): void
    {
        $runner = $this->runner('mra_valid', ['status' => 'active']);
        $this->promptProgram(includeNextPhase: true);
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram(options: [
            'run_mode' => 'controlled_multi_phase',
            'multi_phase_enabled' => true,
        ]);
        [$first, $second] = $job->phaseRuns()->orderBy('phase_order')->get();

        $this->assertSame('assigned', $first->status);
        $this->assertSame('queued', $second->status);

        app(MiriamDevelopmentManagerService::class)->submitPhaseResult($job, $first, $runner, $this->multiPhasePayload());

        $this->assertSame('passed', $first->fresh()->status);
        $this->assertSame('assigned', $second->fresh()->status);
        $this->assertSame('running', $job->fresh()->status);
        $this->assertSame(1, $job->fresh()->completed_phases);
    }

    public function test_controlled_multi_phase_failed_unclear_or_safety_results_do_not_assign_next_phase(): void
    {
        foreach ([
            'failed validation' => $this->multiPhasePayload(validation: ['php_artisan_test' => 'failed'], status: 'failed', parsedStatus: 'failed'),
            'parser unclear' => $this->multiPhasePayload(parsedStatus: 'review_required'),
            'safety risk' => $this->multiPhasePayload(status: 'blocked', parsedStatus: 'blocked', safety: 'blocked'),
        ] as $case => $payload) {
            $this->runner('mra_valid_'.Str::slug($case, '_'), ['status' => 'active', 'slug' => 'runner-'.Str::slug($case), 'name' => 'Runner '.$case]);
            $this->promptProgram(includeNextPhase: true, slug: 'program-'.Str::slug($case));
            $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram(options: [
                'run_mode' => 'controlled_multi_phase',
                'multi_phase_enabled' => true,
            ]);
            $runner = $job->fresh('runnerAgent')->runnerAgent;
            [$first, $second] = $job->phaseRuns()->orderBy('phase_order')->get();

            app(MiriamDevelopmentManagerService::class)->submitPhaseResult($job, $first, $runner, $payload);

            $this->assertSame('queued', $second->fresh()->status, $case);
            $this->assertContains($job->fresh()->status, ['waiting_for_manual_fix', 'blocked'], $case);
        }
    }

    public function test_active_failure_blocks_controlled_multi_phase_progression(): void
    {
        $runner = $this->runner('mra_valid', ['status' => 'active']);
        $this->promptProgram(includeNextPhase: true);
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram(options: [
            'run_mode' => 'controlled_multi_phase',
            'multi_phase_enabled' => true,
        ]);
        [$first, $second] = $job->phaseRuns()->orderBy('phase_order')->get();

        MiriamDevelopmentFailure::create([
            'development_job_id' => $job->id,
            'phase_run_id' => $first->id,
            'runner_agent_id' => $runner->id,
            'failure_type' => 'unknown',
            'severity' => 'medium',
            'title' => 'Existing unresolved gate',
            'status' => 'open',
        ]);

        app(MiriamDevelopmentManagerService::class)->submitPhaseResult($job, $first, $runner, $this->multiPhasePayload());

        $this->assertSame('queued', $second->fresh()->status);
        $this->assertSame('waiting_for_manual_fix', $job->fresh()->status);
    }

    public function test_controlled_multi_phase_all_phases_passed_marks_job_completed(): void
    {
        $runner = $this->runner('mra_valid', ['status' => 'active']);
        $this->promptProgram(includeNextPhase: true);
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram(options: [
            'run_mode' => 'controlled_multi_phase',
            'multi_phase_enabled' => true,
        ]);
        [$first, $second] = $job->phaseRuns()->orderBy('phase_order')->get();

        app(MiriamDevelopmentManagerService::class)->submitPhaseResult($job, $first, $runner, $this->multiPhasePayload());
        app(MiriamDevelopmentManagerService::class)->submitPhaseResult($job->fresh(), $second->fresh(), $runner, $this->multiPhasePayload());

        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame(2, $job->fresh()->completed_phases);
    }

    public function test_failed_validation_does_not_mark_phase_passed(): void
    {
        $this->runner('mra_valid', ['status' => 'active']);
        $this->promptProgram();
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();
        $phaseRun = $job->phaseRuns()->firstOrFail();

        $this->withToken('mra_valid')
            ->postJson("/api/runner/jobs/{$job->id}/phase-runs/{$phaseRun->id}/result", $this->onePhasePayload(validation: ['php_artisan_test' => 'failed'], status: 'failed'))
            ->assertOk()
            ->assertJsonPath('phase_run.status', 'output_received');

        $this->assertSame('waiting_for_manual_fix', $job->fresh()->status);
        $this->assertSame('ready', $phaseRun->phase->fresh()->status);
    }

    public function test_safety_risk_does_not_mark_phase_passed(): void
    {
        $this->runner('mra_valid', ['status' => 'active']);
        $this->promptProgram();
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();
        $phaseRun = $job->phaseRuns()->firstOrFail();

        $this->withToken('mra_valid')
            ->postJson("/api/runner/jobs/{$job->id}/phase-runs/{$phaseRun->id}/result", $this->onePhasePayload(safety: 'blocked', status: 'blocked'))
            ->assertOk()
            ->assertJsonPath('phase_run.status', 'output_received')
            ->assertJsonPath('phase_run.parsed_result.safety_scanner', 'blocked');

        $this->assertSame('waiting_for_manual_fix', $job->fresh()->status);
        $this->assertSame('ready', $phaseRun->phase->fresh()->status);
    }

    public function test_build_failure_is_classified_as_auto_fixable(): void
    {
        $this->runner('mra_valid', ['status' => 'active']);
        $this->promptProgram();
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();
        $phaseRun = $job->phaseRuns()->firstOrFail();
        $phaseRun->update([
            'status' => 'failed',
            'validation_json' => json_encode(['npm_run_build' => 'failed']),
            'codex_stderr' => 'Vite build failed with TypeScript error',
        ]);

        $classification = app(DevelopmentFailureClassifierService::class)->classify($phaseRun->fresh());

        $this->assertSame('build_failed', $classification['failure_type']);
        $this->assertTrue($classification['can_auto_fix']);
        $this->assertFalse($classification['needs_user_at_system']);
    }

    public function test_db_connection_refused_is_classified_as_user_at_system(): void
    {
        $this->runner('mra_valid', ['status' => 'active']);
        $this->promptProgram();
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();
        $phaseRun = $job->phaseRuns()->firstOrFail();
        $phaseRun->update([
            'status' => 'failed',
            'error_message' => 'SQLSTATE[HY000] [2002] Connection refused',
        ]);

        $classification = app(DevelopmentFailureClassifierService::class)->classify($phaseRun->fresh());

        $this->assertSame('local_environment', $classification['failure_type']);
        $this->assertTrue($classification['needs_user_at_system']);
    }

    public function test_failed_phase_creates_failure_record_and_does_not_move_forward(): void
    {
        $this->runner('mra_valid', ['status' => 'active']);
        $this->promptProgram(includeNextPhase: true);
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();
        $phaseRun = $job->phaseRuns()->firstOrFail();

        app(MiriamDevelopmentManagerService::class)->submitPhaseResult(
            $job,
            $phaseRun,
            MiriamRunnerAgent::firstOrFail(),
            $this->onePhasePayload(validation: ['npm_run_build' => 'failed'], status: 'failed')
        );

        $this->assertDatabaseHas('miriam_development_failures', [
            'development_job_id' => $job->id,
            'phase_run_id' => $phaseRun->id,
            'failure_type' => 'build_failed',
            'can_auto_fix' => true,
        ]);
        $this->assertSame('waiting_for_manual_fix', $job->fresh()->status);
        $this->assertSame('queued', MiriamPromptPhase::where('phase_key', 'phase_next')->firstOrFail()->status);
    }

    public function test_apply_fix_creates_fix_attempt_and_runner_instruction(): void
    {
        $runner = $this->runner('mra_valid', ['status' => 'active']);
        $this->promptProgram();
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();
        app(MiriamDevelopmentManagerService::class)->submitPhaseResult($job, $job->phaseRuns()->firstOrFail(), $runner, $this->onePhasePayload(validation: ['npm_run_build' => 'failed'], status: 'failed'));
        $failure = MiriamDevelopmentFailure::firstOrFail();

        app(DevelopmentFailureRecoveryService::class)->applyFix($failure);

        $this->assertDatabaseHas('miriam_development_fix_attempts', [
            'development_failure_id' => $failure->id,
            'status' => 'queued',
        ]);

        $this->withToken('mra_valid')
            ->getJson("/api/runner/jobs/{$job->id}/instruction")
            ->assertOk()
            ->assertJsonPath('instruction.action', 'run_fix_attempt')
            ->assertJsonPath('instruction.failure_id', $failure->id);
    }

    public function test_runner_fix_result_marks_failure_fixed_without_advancing_phase(): void
    {
        $runner = $this->runner('mra_valid', ['status' => 'active']);
        $this->promptProgram(includeNextPhase: true);
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();
        app(MiriamDevelopmentManagerService::class)->submitPhaseResult($job, $job->phaseRuns()->firstOrFail(), $runner, $this->onePhasePayload(validation: ['php_artisan_test' => 'failed'], status: 'failed'));
        $failure = MiriamDevelopmentFailure::firstOrFail();
        app(DevelopmentFailureRecoveryService::class)->applyFix($failure);

        $this->withToken('mra_valid')
            ->postJson("/api/runner/failures/{$failure->id}/fix-result", [
                'status' => 'passed',
                'validation_json' => ['php_artisan_test' => 'passed'],
                'files_changed_json' => ['app/Fix.php'],
            ])
            ->assertOk()
            ->assertJsonPath('failure.status', 'fixed');

        $this->assertSame('waiting_for_approval', $job->fresh()->status);
        $this->assertSame('queued', MiriamPromptPhase::where('phase_key', 'phase_next')->firstOrFail()->status);
    }

    public function test_manual_fix_resume_and_rollback_instructions_are_explicit(): void
    {
        $runner = $this->runner('mra_valid', ['status' => 'active']);
        $this->promptProgram();
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();
        app(MiriamDevelopmentManagerService::class)->submitPhaseResult($job, $job->phaseRuns()->firstOrFail(), $runner, $this->onePhasePayload(validation: ['php_artisan_test' => 'failed'], status: 'failed'));
        $failure = MiriamDevelopmentFailure::firstOrFail();

        app(DevelopmentFailureRecoveryService::class)->markManualAttentionRequired($failure);
        app(DevelopmentFailureRecoveryService::class)->resumeAfterManualFix($failure->fresh());

        $this->withToken('mra_valid')
            ->getJson("/api/runner/jobs/{$job->id}/instruction")
            ->assertOk()
            ->assertJsonPath('instruction.action', 'validate_after_manual_fix');

        app(DevelopmentFailureRecoveryService::class)->requestRollback($failure->fresh());

        $this->withToken('mra_valid')
            ->getJson("/api/runner/jobs/{$job->id}/instruction")
            ->assertOk()
            ->assertJsonPath('instruction.action', 'rollback_phase');
    }

    public function test_stop_job_cancels_safely(): void
    {
        $runner = $this->runner('mra_valid', ['status' => 'active']);
        $this->promptProgram();
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();
        app(MiriamDevelopmentManagerService::class)->submitPhaseResult($job, $job->phaseRuns()->firstOrFail(), $runner, $this->onePhasePayload(validation: ['php_artisan_test' => 'failed'], status: 'failed'));

        app(DevelopmentFailureRecoveryService::class)->stopJob($job);

        $this->assertSame('cancelled', $job->fresh()->status);
        $this->assertSame('stopped', MiriamDevelopmentFailure::firstOrFail()->status);
    }

    public function test_runner_token_is_not_exposed_in_responses(): void
    {
        $token = 'mra_super_secret_runner_token';
        $this->runner($token, ['status' => 'active']);

        $response = $this->withToken($token)
            ->postJson('/api/runner/heartbeat', ['machine_name' => 'Sam-PC'])
            ->assertOk();

        $responseText = $response->getContent();
        $this->assertStringNotContainsString($token, $responseText);
        $this->assertStringNotContainsString('token_hash', $responseText);
    }

    public function test_runner_can_fail_job_and_complete_only_after_phase_allows_it(): void
    {
        $this->runner('mra_valid', ['status' => 'active']);
        $this->promptProgram();
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();

        $this->withToken('mra_valid')
            ->postJson("/api/runner/jobs/{$job->id}/complete")
            ->assertStatus(422);

        $this->withToken('mra_valid')
            ->postJson("/api/runner/jobs/{$job->id}/fail", ['error_message' => 'Dry run failed'])
            ->assertOk()
            ->assertJsonPath('job.status', 'failed');
    }

    public function test_instruction_endpoint_returns_safe_prompt_payload(): void
    {
        $this->runner('mra_valid', ['status' => 'active']);
        $this->promptProgram();
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();

        $this->withToken('mra_valid')
            ->getJson("/api/runner/jobs/{$job->id}/instruction")
            ->assertOk()
            ->assertJsonPath('phase_run.runner_instruction.do_not_auto_deploy', true)
            ->assertJsonPath('phase_run.runner_instruction.do_not_use_git_as_primary_workflow', true);
    }

    public function test_development_manager_page_requires_auth_and_loads_for_user(): void
    {
        $this->withoutVite();

        $this->get(route('product-brain.development-manager.index'))->assertRedirect('/login');

        [$user] = $this->context();

        $this->actingAs($user)
            ->get(route('product-brain.development-manager.index'))
            ->assertOk();
    }

    public function test_development_manager_page_displays_dry_run_status_props(): void
    {
        $this->withoutVite();
        [$user] = $this->context();
        $this->runner('mra_valid', ['status' => 'active']);
        $this->promptProgram();
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram($user);
        $phaseRun = $job->phaseRuns()->firstOrFail();
        app(MiriamDevelopmentManagerService::class)->submitPhaseResult($job, $phaseRun, MiriamRunnerAgent::firstOrFail(), $this->dryRunPayload());

        $this->actingAs($user)
            ->get(route('product-brain.development-manager.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ProductBrain/DevelopmentManager')
                ->where('jobs.0.phase_runs.0.status', 'output_received')
                ->where('jobs.0.phase_runs.0.is_dry_run', true)
                ->where('jobs.0.events.0.event_type', 'local_runner_dry_run_received'));
    }

    public function test_development_manager_page_displays_one_phase_artifacts(): void
    {
        $this->withoutVite();
        [$user] = $this->context();
        $this->runner('mra_valid', ['status' => 'active']);
        $this->promptProgram();
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram($user);
        app(MiriamDevelopmentManagerService::class)->submitPhaseResult($job, $job->phaseRuns()->firstOrFail(), MiriamRunnerAgent::firstOrFail(), $this->onePhasePayload());

        $this->actingAs($user)
            ->get(route('product-brain.development-manager.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ProductBrain/DevelopmentManager')
                ->where('jobs.0.phase_runs.0.is_one_phase_execution', true)
                ->where('jobs.0.phase_runs.0.safety_scanner', 'passed')
                ->where('jobs.0.phase_runs.0.files_changed.0', 'app/Example.php')
                ->where('jobs.0.phase_runs.0.backup_paths.0', 'storage/app/backups/source.zip'));
    }

    public function test_development_manager_page_displays_failure_recovery_props(): void
    {
        $this->withoutVite();
        [$user] = $this->context();
        $runner = $this->runner('mra_valid', ['status' => 'active']);
        $this->promptProgram();
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram($user);
        app(MiriamDevelopmentManagerService::class)->submitPhaseResult($job, $job->phaseRuns()->firstOrFail(), $runner, $this->onePhasePayload(validation: ['npm_run_build' => 'failed'], status: 'failed'));

        $this->actingAs($user)
            ->get(route('product-brain.development-manager.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ProductBrain/DevelopmentManager')
                ->where('jobs.0.failures.0.failure_type', 'build_failed')
                ->where('jobs.0.failures.0.can_auto_fix', true));
    }

    public function test_app_user_can_start_and_cancel_queued_job(): void
    {
        [$user] = $this->context();
        $this->promptProgram();

        $this->actingAs($user)
            ->post(route('product-brain.development-manager.jobs.store'))
            ->assertRedirect();

        $job = MiriamDevelopmentJob::firstOrFail();

        $this->actingAs($user)
            ->patch(route('product-brain.development-manager.jobs.cancel', $job))
            ->assertRedirect();

        $this->assertSame('cancelled', $job->fresh()->status);
    }

    public function test_slack_dev_status_go_and_stop_are_deterministic(): void
    {
        $this->seedSlackContext();

        $this->postSlack('/miriam dev status')->assertOk();
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'Miriam Development Manager status'));

        $this->postSlack('/miriam dev go')->assertOk();
        $this->assertDatabaseHas('miriam_development_jobs', ['status' => 'waiting_for_runner']);
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'Job created. Waiting for local runner'));

        $this->postSlack('/miriam dev stop')->assertOk();
        $this->assertSame('cancelled', MiriamDevelopmentJob::firstOrFail()->status);
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'cancelled'));
    }

    public function test_slack_dev_go_multi_pause_and_resume_are_safe_cloud_actions(): void
    {
        $this->seedSlackContext();
        $this->runner('mra_valid', ['status' => 'active']);

        $this->postSlack('/miriam dev go multi')->assertOk();

        $job = MiriamDevelopmentJob::latest()->firstOrFail();
        $this->assertSame('controlled_multi_phase', $job->run_mode);
        $this->assertTrue((bool) ($job->options()['multi_phase_enabled'] ?? false));

        $this->postSlack('/miriam dev pause')->assertOk();
        $this->assertSame('paused', $job->fresh()->status);

        $this->postSlack('/miriam dev resume')->assertOk();
        $this->assertContains($job->fresh()->status, ['queued', 'running']);
    }

    public function test_slack_dev_status_includes_runner_heartbeat_and_dry_run(): void
    {
        $this->seedSlackContext();
        $runner = $this->runner('mra_valid', ['status' => 'active', 'last_seen_at' => now()]);
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();
        app(MiriamDevelopmentManagerService::class)->submitPhaseResult($job, $job->phaseRuns()->firstOrFail(), $runner, $this->dryRunPayload());

        $this->postSlack('/miriam dev status')->assertOk();

        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'Latest runner heartbeat')
            && str_contains((string) $request->body(), 'Latest dry-run')
            && str_contains((string) $request->body(), 'Waiting for real execution: yes'));
    }

    public function test_slack_dev_status_includes_one_phase_result_summary(): void
    {
        $this->seedSlackContext();
        $runner = $this->runner('mra_valid', ['status' => 'active', 'last_seen_at' => now()]);
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();
        app(MiriamDevelopmentManagerService::class)->submitPhaseResult($job, $job->phaseRuns()->firstOrFail(), $runner, $this->onePhasePayload());

        $this->postSlack('/miriam dev status')->assertOk();

        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'Latest one-phase execution')
            && str_contains((string) $request->body(), 'Changed files: 1')
            && str_contains((string) $request->body(), 'Safety scanner: passed')
            && str_contains((string) $request->body(), 'Paused after one phase: yes'));
    }

    public function test_runner_monitor_classifies_runner_health_states(): void
    {
        $this->runner('mra_online', ['slug' => 'runner-online', 'name' => 'Runner Online', 'status' => 'active', 'last_seen_at' => now()]);
        $this->runner('mra_stale', ['slug' => 'runner-stale', 'name' => 'Runner Stale', 'status' => 'active', 'last_seen_at' => now()->subMinutes(6)]);
        $this->runner('mra_offline', ['slug' => 'runner-offline', 'name' => 'Runner Offline', 'status' => 'active', 'last_seen_at' => now()->subMinutes(16)]);
        $this->runner('mra_disabled', ['slug' => 'runner-disabled', 'name' => 'Runner Disabled', 'status' => 'disabled']);

        $runners = collect(app(MiriamRunnerMonitoringService::class)->summary()['runners'])->keyBy('slug');

        $this->assertSame('online', $runners['runner-online']['health']);
        $this->assertSame('stale', $runners['runner-stale']['health']);
        $this->assertSame('offline', $runners['runner-offline']['health']);
        $this->assertSame('disabled', $runners['runner-disabled']['health']);
    }

    public function test_runner_monitor_reports_blocked_failed_manual_and_release_approval_conditions(): void
    {
        [$job] = $this->completedAppJob();
        $runner = $job->runnerAgent;
        $package = app(MiriamReleasePackageService::class)->requestForJob($job->fresh(['managedApp', 'runnerAgent']));
        $package = app(MiriamReleasePackageService::class)->recordResult($package, $runner, $this->releasePayload());
        $job->update(['status' => 'blocked', 'error_message' => 'Blocked for monitor test.']);
        $phaseRun = $job->phaseRuns()->firstOrFail();

        MiriamDevelopmentFailure::create([
            'development_job_id' => $job->id,
            'phase_run_id' => $phaseRun->id,
            'runner_agent_id' => $runner->id,
            'failure_type' => 'build_failed',
            'severity' => 'high',
            'title' => 'Build failed for monitor test',
            'summary' => 'npm build failed',
            'can_auto_fix' => true,
            'needs_user_at_system' => false,
            'status' => 'open',
        ]);

        $summary = app(MiriamRunnerMonitoringService::class)->summary();

        $this->assertSame('attention_required', $summary['overall_status']);
        $this->assertSame(1, $summary['active_failure_count']);
        $this->assertSame(1, $summary['pending_release_approval_count']);
        $this->assertGreaterThanOrEqual(1, $summary['manual_action_count']);
        $this->assertTrue(collect($summary['alerts'])->contains(fn ($alert) => $alert['type'] === 'job_blocked'));
        $this->assertTrue(collect($summary['alerts'])->contains(fn ($alert) => $alert['type'] === 'release_approval_pending'));
        $this->assertSame('pending', $package->latestApproval()?->status);
    }

    public function test_runner_monitor_commands_are_safe_with_no_runners_or_jobs(): void
    {
        $this->artisan('miriam:runner-monitor', ['--no-slack' => true])
            ->expectsOutputToContain('Miriam Development Manager monitor')
            ->assertExitCode(0);

        $this->artisan('miriam:dev:summary')
            ->expectsOutputToContain('Miriam Development Manager summary')
            ->expectsOutputToContain('Active jobs: 0')
            ->assertExitCode(0);
    }

    public function test_runner_monitor_output_does_not_expose_tokens(): void
    {
        $this->runner('mra_secret_monitor_token', ['status' => 'active', 'last_seen_at' => now()]);

        $text = app(MiriamRunnerMonitoringService::class)->textSummary();

        $this->assertStringNotContainsString('mra_secret_monitor_token', $text);
        $this->assertStringNotContainsString('token_hash', $text);
    }

    public function test_close_verification_artifacts_disables_temp_runners_and_closes_records_without_deleting(): void
    {
        [$job] = $this->completedAppJob();
        $runner = $job->runnerAgent;
        $runner->update(['name' => 'Phase 3G Verification Runner', 'slug' => 'phase-3g-verification-runner', 'status' => 'active']);
        $job->update([
            'status' => 'waiting_for_approval',
            'options_json' => json_encode(['source' => 'phase_3k_1_verification', 'verification_test' => true]),
        ]);
        $phaseRun = $job->phaseRuns()->firstOrFail();
        $failure = MiriamDevelopmentFailure::create([
            'development_job_id' => $job->id,
            'phase_run_id' => $phaseRun->id,
            'runner_agent_id' => $runner->id,
            'failure_type' => 'test_failed',
            'severity' => 'medium',
            'title' => 'Verification failure artifact',
            'summary' => 'Test-only failure.',
            'status' => 'open',
        ]);
        $package = app(MiriamReleasePackageService::class)->requestForJob($job->fresh(['managedApp', 'runnerAgent'])->forceFill(['status' => 'completed']));
        $package = app(MiriamReleasePackageService::class)->recordResult($package, $runner, $this->releasePayload());

        $this->artisan('miriam:dev:close-verification-artifacts')
            ->expectsOutputToContain('Closed Miriam verification artifacts without deleting records.')
            ->assertExitCode(0);

        $this->assertSame('disabled', $runner->fresh()->status);
        $this->assertSame('cancelled', $job->fresh()->status);
        $this->assertSame('stopped', $failure->fresh()->status);
        $this->assertSame('archived', $package->fresh()->status);
        $this->assertSame('rejected', $package->fresh()->latestApproval()?->status);
        $this->assertDatabaseHas('miriam_development_job_events', [
            'development_job_id' => $job->id,
            'event_type' => 'verification_job_closed',
        ]);
        $this->assertDatabaseCount('miriam_development_jobs', 1);
        $this->assertDatabaseCount('miriam_release_packages', 1);
    }

    public function test_runner_bootstrap_local_creates_ignored_safe_config_and_assigns_apps_without_printing_token(): void
    {
        $config = 'tools/miriam-runner/runner-config.test.local.json';
        File::delete(base_path($config));
        $this->artisan('miriam:apps:seed-defaults')->assertExitCode(0);

        MiriamManagedApp::whereIn('slug', ['miriam-taskflow', 'catererhq'])->get()
            ->each(fn (MiriamManagedApp $app) => $app->update([
                'local_project_path' => base_path(),
                'status' => 'active',
                'config_json' => json_encode(array_merge($app->config(), ['placeholder' => false])),
            ]));

        MiriamManagedApp::where('slug', 'churchforce')->first()?->update([
            'local_project_path' => 'C:\\missing\\churchforce',
        ]);

        $exitCode = Artisan::call('miriam:runner-bootstrap-local', [
            '--name' => 'Main Windows Runner',
            '--apps' => 'miriam-taskflow,churchforce,catererhq,sayaraforce,photographerhq',
            '--config' => $config,
            '--force' => true,
        ]);
        $output = Artisan::output();
        $runner = MiriamRunnerAgent::where('name', 'Main Windows Runner')->firstOrFail();
        $configData = json_decode(File::get(base_path($config)), true);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Miriam local runner bootstrap complete.', $output);
        $this->assertStringContainsString('Runner token was written to the ignored local config and was not printed.', $output);
        $this->assertSame(64, strlen($runner->token_hash));
        $this->assertStringNotContainsString((string) $configData['runner_token'], $output);
        $this->assertStringStartsWith('mra_', $configData['runner_token']);
        $this->assertTrue($configData['dry_run']);
        $this->assertFalse($configData['real_execution_enabled']);
        $this->assertFalse($configData['run_codex']);
        $this->assertFalse($configData['multi_phase_enabled']);
        $this->assertFalse($configData['release_packaging_enabled']);
        $this->assertSame('http://taskflow.test', $configData['cloud_url']);
        $this->assertSame('C:\\laragon\\www\\taskflow', $configData['local_project_path']);
        $this->assertSame(['C:\\laragon\\www'], $configData['allowed_workspace_roots']);

        foreach (['miriam-taskflow', 'churchforce', 'catererhq'] as $slug) {
            $this->assertSame($runner->id, MiriamManagedApp::where('slug', $slug)->firstOrFail()->default_runner_agent_id);
        }

        $this->assertNotSame($runner->id, MiriamManagedApp::where('slug', 'sayaraforce')->firstOrFail()->default_runner_agent_id);
        $this->assertNotSame($runner->id, MiriamManagedApp::where('slug', 'photographerhq')->firstOrFail()->default_runner_agent_id);
        $this->assertStringContainsString('tools/miriam-runner/*.local.json', File::get(base_path('.gitignore')));

        File::delete(base_path($config));
    }

    public function test_monitor_deduplicates_slack_alerts_for_same_issue(): void
    {
        $this->seedSlackContext();
        Cache::flush();
        $runner = $this->runner('mra_alert_runner', [
            'name' => 'Real Alert Runner',
            'slug' => 'real-alert-runner',
            'status' => 'active',
            'last_seen_at' => now()->subMinutes(20),
        ]);
        app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram(options: ['runner_agent_id' => $runner->id]);

        $monitor = app(MiriamRunnerMonitoringService::class);
        $summary = $monitor->summary();
        $first = $monitor->sendSlackAlerts($summary);
        $second = $monitor->sendSlackAlerts($summary);

        $this->assertGreaterThan(0, $first['sent']);
        $this->assertSame(0, $second['sent']);
        $this->assertGreaterThan(0, $second['skipped']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'Miriam Development Manager alert summary')
            && ! str_contains((string) $request->body(), 'Miriam Development Manager alert"'));
    }

    public function test_monitor_alerts_can_be_muted_unmuted_and_reported(): void
    {
        $this->seedSlackContext();
        Cache::flush();
        $runner = $this->runner('mra_muted_alert_runner', [
            'name' => 'Muted Alert Runner',
            'slug' => 'muted-alert-runner',
            'status' => 'active',
            'last_seen_at' => now()->subMinutes(20),
        ]);
        app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram(options: ['runner_agent_id' => $runner->id]);

        $this->artisan('miriam:dev:alerts-status')
            ->expectsOutputToContain('not muted')
            ->assertExitCode(0);

        $this->artisan('miriam:dev:alerts-mute')
            ->expectsOutputToContain('alerts muted')
            ->assertExitCode(0);

        $result = app(MiriamRunnerMonitoringService::class)->sendSlackAlerts();

        $this->assertSame(0, $result['sent']);
        $this->assertTrue($result['muted']);
        Http::assertNothingSent();

        $this->artisan('miriam:dev:alerts-status')
            ->expectsOutputToContain('muted since')
            ->assertExitCode(0);

        $this->artisan('miriam:dev:alerts-unmute')
            ->expectsOutputToContain('alerts unmuted')
            ->assertExitCode(0);

        $this->assertFalse(app(MiriamRunnerMonitoringService::class)->alertsMuted());
    }

    public function test_waiting_for_approval_records_ledger_and_sends_one_summary_without_approval_card(): void
    {
        $this->seedSlackContext();
        Cache::flush();
        $runner = $this->runner('mra_approval_notice', ['status' => 'active', 'last_seen_at' => now()]);
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();

        app(MiriamDevelopmentManagerService::class)->submitPhaseResult(
            $job,
            $job->phaseRuns()->firstOrFail(),
            $runner,
            $this->onePhasePayload()
        );

        $this->assertSame('waiting_for_approval', $job->fresh()->status);
        $this->assertDatabaseHas('miriam_development_ledgers', [
            'job_id' => $job->id,
            'status' => 'completed',
            'test_result' => 'passed',
        ]);
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'Miriam development update')
            && ! str_contains((string) $request->body(), 'mra_approval_notice')
            && ! str_contains((string) $request->body(), 'xoxb-test'));
        Http::assertNotSent(fn ($request) => str_contains((string) $request->body(), 'Miriam Development Manager needs approval'));
    }

    public function test_manual_validation_requested_stays_quiet_for_normal_failure(): void
    {
        $this->seedSlackContext();
        Cache::flush();
        $failure = $this->developmentFailure();

        Http::fake(['slack.com/*' => Http::response(['ok' => true, 'channel' => 'C123', 'ts' => '456.789'])]);
        Cache::flush();

        app(DevelopmentFailureRecoveryService::class)->resumeAfterManualFix($failure);

        Http::assertNothingSent();
    }

    public function test_safety_gate_slack_approval_notification_deduplicates_same_gate(): void
    {
        $this->seedSlackContext();
        Cache::flush();
        $failure = $this->developmentFailure(autoFix: false);
        $failure->update([
            'failure_type' => 'safety_risk',
            'severity' => 'critical',
            'needs_user_at_system' => true,
            'summary' => 'Destructive database command requested.',
        ]);

        Http::fake(['slack.com/*' => Http::response(['ok' => true, 'channel' => 'C123', 'ts' => '789.123'])]);
        Cache::flush();

        $notifier = app(\App\Services\MiriamDevelopmentApprovalNotifier::class);
        $notifier->notifyFailureNeedsAttention($failure->fresh(['job.managedApp', 'phaseRun.phase', 'fixAttempts']));
        $notifier->notifyFailureNeedsAttention($failure->fresh(['job.managedApp', 'phaseRun.phase', 'fixAttempts']));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'Miriam Development Manager needs approval'));
    }

    public function test_proactive_slack_notification_does_not_expose_tokens_or_secrets(): void
    {
        $this->seedSlackContext();
        Cache::flush();
        $runner = $this->runner('mra_do_not_leak_this_token', [
            'status' => 'active',
            'last_seen_at' => now(),
            'name' => 'Safe Runner',
            'slug' => 'safe-runner',
        ]);
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram(options: ['runner_agent_id' => $runner->id]);

        app(MiriamDevelopmentManagerService::class)->submitPhaseResult(
            $job,
            $job->phaseRuns()->firstOrFail(),
            $runner,
            $this->onePhasePayload()
        );

        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'Miriam development update')
            && ! str_contains((string) $request->body(), 'mra_do_not_leak_this_token')
            && ! str_contains((string) $request->body(), 'token_hash')
            && ! str_contains((string) $request->body(), 'xoxb-test'));
    }

    public function test_slack_dev_failures_returns_safe_summary_and_actions_require_signature(): void
    {
        $this->seedSlackContext();
        $runner = $this->runner('mra_valid', ['status' => 'active', 'last_seen_at' => now()]);
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();
        app(MiriamDevelopmentManagerService::class)->submitPhaseResult($job, $job->phaseRuns()->firstOrFail(), $runner, $this->onePhasePayload(validation: ['npm_run_build' => 'failed'], status: 'failed'));

        $this->postJson(route('webhooks.slack.events'), ['event' => ['channel' => 'C123', 'user' => 'U123', 'text' => '/miriam dev failures']])
            ->assertForbidden();

        $this->postSlack('/miriam dev failures')->assertOk();

        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'Miriam development failure')
            && str_contains((string) $request->body(), '/miriam dev apply fix')
            && str_contains((string) $request->body(), 'dev_apply_fix'));
    }

    public function test_slack_monitor_summary_runner_status_and_alert_commands_return_safe_text(): void
    {
        $this->seedSlackContext();
        $this->runner('mra_monitor_valid', ['status' => 'active', 'last_seen_at' => now()]);

        $this->postSlack('/miriam dev monitor')->assertOk();
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'Miriam Development Manager monitor')
            && ! str_contains((string) $request->body(), 'mra_monitor_valid'));

        $this->postSlack('/miriam dev summary')->assertOk();
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'Miriam Development Manager summary'));

        $this->postSlack('/miriam runner status')->assertOk();
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'Miriam runner status')
            && str_contains((string) $request->body(), 'online'));

        $this->postSlack('/miriam runner alerts')->assertOk();
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'No active Miriam Development Manager alert conditions'));
    }

    public function test_slack_slash_command_dev_summary_routes_to_development_manager_summary(): void
    {
        $this->seedSlackContext();
        $this->runner('mra_summary_slash', ['status' => 'active', 'last_seen_at' => now()]);

        $response = $this->postSlackSlash('dev summary')
            ->assertOk()
            ->assertJsonPath('response_type', 'ephemeral');

        $this->assertStringContainsString('Miriam Development Manager summary', $response->json('text'));
        $this->assertStringNotContainsString('Miriam Prompt OS commands', $response->json('text'));
        $this->assertStringContainsString('Active jobs:', $response->json('text'));
    }

    public function test_slack_slash_command_dev_monitor_and_runner_status_still_route(): void
    {
        $this->seedSlackContext();
        $this->runner('mra_monitor_slash', ['status' => 'active', 'last_seen_at' => now()]);

        $monitor = $this->postSlackSlash('dev monitor')
            ->assertOk()
            ->assertJsonPath('response_type', 'ephemeral');

        $this->assertStringContainsString('Miriam Development Manager monitor', $monitor->json('text'));
        $this->assertStringNotContainsString('Miriam Prompt OS commands', $monitor->json('text'));

        $runner = $this->postSlackSlash('runner status')
            ->assertOk()
            ->assertJsonPath('response_type', 'ephemeral');

        $this->assertStringContainsString('Miriam runner status', $runner->json('text'));
        $this->assertStringContainsString('online', $runner->json('text'));
        $this->assertStringNotContainsString('Miriam Prompt OS commands', $runner->json('text'));
    }

    public function test_slack_slash_command_dev_failures_returns_failure_blocks_not_generic_ok(): void
    {
        $this->seedSlackContext();
        $failure = $this->developmentFailure();

        $response = $this->postSlackSlash('dev failures')
            ->assertOk()
            ->assertJsonPath('response_type', 'ephemeral')
            ->assertJsonPath('blocks.0.type', 'section')
            ->assertJsonPath('blocks.1.type', 'actions');

        $this->assertStringNotContainsString('"ok":true', $response->getContent());
        $this->assertStringContainsString("Failure #{$failure->id}", $response->getContent());
        $this->assertStringContainsString('dev_show_error', $response->getContent());
        $this->assertStringContainsString('dev_stop_job', $response->getContent());
    }

    public function test_slack_slash_command_unknown_command_returns_helpful_text(): void
    {
        $this->seedSlackContext();

        $response = $this->postSlackSlash('what is this')
            ->assertOk()
            ->assertJsonPath('response_type', 'ephemeral');

        $this->assertStringContainsString('Miriam Prompt OS commands', $response->json('text'));
        $this->assertStringNotContainsString('"ok":true', $response->getContent());
    }

    public function test_slack_apply_fix_creates_attempt(): void
    {
        $this->seedSlackContext();
        $runner = $this->runner('mra_valid', ['status' => 'active']);
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();
        app(MiriamDevelopmentManagerService::class)->submitPhaseResult($job, $job->phaseRuns()->firstOrFail(), $runner, $this->onePhasePayload(validation: ['npm_run_build' => 'failed'], status: 'failed'));
        $failure = MiriamDevelopmentFailure::firstOrFail();

        $this->postSlack("/miriam dev apply fix {$failure->id}")->assertOk();

        $this->assertDatabaseHas('miriam_development_fix_attempts', [
            'development_failure_id' => $failure->id,
            'status' => 'queued',
        ]);
    }

    public function test_slack_approve_job_approves_waiting_for_approval_job(): void
    {
        $this->seedSlackContext();
        $runner = $this->runner('mra_approve_job', ['status' => 'active']);
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram(options: ['runner_agent_id' => $runner->id]);
        app(MiriamDevelopmentManagerService::class)->submitPhaseResult($job, $job->phaseRuns()->firstOrFail(), $runner, $this->onePhasePayload());

        $this->postSlackSlash("dev approve job {$job->id}")
            ->assertOk()
            ->assertJsonPath('text', "Job #{$job->id} approved and completed.");

        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame('passed', $job->phaseRuns()->firstOrFail()->fresh()->status);
        $this->assertDatabaseHas('miriam_development_job_events', [
            'development_job_id' => $job->id,
            'event_type' => 'approval_gate_approved',
        ]);
    }

    public function test_slack_approve_alias_and_button_use_same_approval_flow(): void
    {
        $this->seedSlackContext();
        $runner = $this->runner('mra_approve_alias', ['status' => 'active']);
        $aliasJob = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram(options: ['runner_agent_id' => $runner->id]);
        app(MiriamDevelopmentManagerService::class)->submitPhaseResult($aliasJob, $aliasJob->phaseRuns()->firstOrFail(), $runner, $this->onePhasePayload());

        $this->postSlackSlash("dev approve {$aliasJob->id}")
            ->assertOk()
            ->assertJsonPath('text', "Job #{$aliasJob->id} approved and completed.");

        $buttonJob = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram(options: ['runner_agent_id' => $runner->id]);
        app(MiriamDevelopmentManagerService::class)->submitPhaseResult($buttonJob, $buttonJob->phaseRuns()->firstOrFail(), $runner, $this->onePhasePayload());

        $this->postSlackInteraction("dev_approve_job:{$buttonJob->id}")
            ->assertOk()
            ->assertJsonPath('text', "Job #{$buttonJob->id} approved and completed.");

        $this->assertSame('completed', $aliasJob->fresh()->status);
        $this->assertSame('completed', $buttonJob->fresh()->status);
    }

    public function test_waiting_for_approval_failure_actions_show_approve_complete_instead_of_resume(): void
    {
        $this->seedSlackContext();
        $runner = $this->runner('mra_approve_failure_buttons', ['status' => 'active']);
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram(options: ['runner_agent_id' => $runner->id]);
        app(MiriamDevelopmentManagerService::class)->submitPhaseResult($job, $job->phaseRuns()->firstOrFail(), $runner, $this->onePhasePayload(validation: ['php_artisan_test' => 'failed'], status: 'failed'));
        $failure = MiriamDevelopmentFailure::latest()->firstOrFail();
        $job->update(['status' => 'waiting_for_approval']);

        $response = $this->postSlackSlash('dev failures')
            ->assertOk()
            ->assertSee("dev_approve_job:{$job->id}", false);

        $blockText = collect($response->json('blocks.1.elements'))->pluck('text.text')->implode('|');

        $this->assertStringContainsString('Approve / Complete', $blockText);
        $this->assertStringNotContainsString('dev_resume_after_manual_fix', $response->getContent());
        $this->assertSame('open', $failure->fresh()->status);
    }

    public function test_approval_fails_if_validation_did_not_pass_or_failure_unresolved(): void
    {
        $this->seedSlackContext();
        $runner = $this->runner('mra_approve_fail', ['status' => 'active']);
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram(options: ['runner_agent_id' => $runner->id]);
        app(MiriamDevelopmentManagerService::class)->submitPhaseResult(
            $job,
            $job->phaseRuns()->firstOrFail(),
            $runner,
            $this->onePhasePayload(validation: ['php_artisan_test' => 'failed'], status: 'failed')
        );

        $this->postSlackSlash("dev approve job {$job->id}")
            ->assertOk()
            ->assertJsonPath('response_type', 'ephemeral');

        $this->assertSame('waiting_for_manual_fix', $job->fresh()->status);
        $this->assertNotSame('completed', $job->fresh()->status);
    }

    public function test_validation_only_job_becomes_completed_after_manual_validation_approval(): void
    {
        $this->seedSlackContext();
        $runner = $this->runner('mra_validation_approval', ['status' => 'active']);
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram(options: [
            'runner_agent_id' => $runner->id,
            'run_mode' => 'validation_only',
        ]);
        app(MiriamDevelopmentManagerService::class)->submitPhaseResult(
            $job,
            $job->phaseRuns()->firstOrFail(),
            $runner,
            $this->onePhasePayload(validation: ['php_artisan_test' => 'failed'], status: 'validation_only_failed', parsed: [
                'status' => 'failed',
                'validation_only' => true,
                'validation' => ['php_artisan_test' => 'failed'],
                'blockers' => [],
            ])
        );
        $failure = MiriamDevelopmentFailure::latest()->firstOrFail();

        app(DevelopmentFailureRecoveryService::class)->recordManualValidationResult($failure, $runner, [
            'status' => 'passed',
            'validation_json' => ['php_artisan_test' => 'passed'],
        ]);

        $this->assertSame('waiting_for_approval', $job->fresh()->status);
        $this->assertSame('fixed', $failure->fresh()->status);

        $this->postSlackSlash("dev approve job {$job->id}")
            ->assertOk()
            ->assertJsonPath('text', "Job #{$job->id} approved and completed.");

        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame('passed', $job->phaseRuns()->firstOrFail()->fresh()->status);
    }

    public function test_slack_dev_resume_remains_for_paused_jobs_only(): void
    {
        $this->seedSlackContext();
        $runner = $this->runner('mra_resume_still_paused_only', ['status' => 'active']);
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram(options: ['runner_agent_id' => $runner->id]);
        app(MiriamDevelopmentManagerService::class)->submitPhaseResult($job, $job->phaseRuns()->firstOrFail(), $runner, $this->onePhasePayload());

        $this->postSlackSlash('dev resume')
            ->assertOk()
            ->assertJsonPath('text', 'No paused Miriam development job was found to resume.');

        $this->assertSame('waiting_for_approval', $job->fresh()->status);
    }

    public function test_slack_interaction_payload_routes_to_apply_fix(): void
    {
        $this->seedSlackContext();
        $runner = $this->runner('mra_valid', ['status' => 'active']);
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();
        app(MiriamDevelopmentManagerService::class)->submitPhaseResult($job, $job->phaseRuns()->firstOrFail(), $runner, $this->onePhasePayload(validation: ['npm_run_build' => 'failed'], status: 'failed'));
        $failure = MiriamDevelopmentFailure::firstOrFail();

        $this->postSlackInteraction("dev_apply_fix:{$failure->id}")
            ->assertOk()
            ->assertJsonPath('response_type', 'ephemeral');

        $this->assertDatabaseHas('miriam_development_fix_attempts', [
            'development_failure_id' => $failure->id,
            'status' => 'queued',
        ]);
    }

    public function test_slack_interaction_payload_rejects_invalid_signature_and_unauthorized_user(): void
    {
        $this->seedSlackContext();

        $this->postSlackInteraction('dev_show_error:1', signature: 'v0=bad')
            ->assertForbidden();

        $this->postSlackInteraction('dev_show_error:1', user: 'U999')
            ->assertOk()
            ->assertJsonPath('text', 'You are not allowed to run this Miriam action.');
    }

    public function test_slack_interaction_payload_routes_to_show_manual_resume_and_rollback_actions(): void
    {
        $this->seedSlackContext();

        $showFailure = $this->developmentFailure();
        $this->postSlackInteraction("dev_show_error:{$showFailure->id}")
            ->assertOk()
            ->assertJsonPath('response_type', 'ephemeral');
        $this->assertSame('open', $showFailure->fresh()->status);

        $manualFailure = $this->developmentFailure();
        $this->postSlackInteraction("dev_manual_fix:{$manualFailure->id}")
            ->assertOk()
            ->assertJsonPath('response_type', 'ephemeral');
        $this->assertSame('manual_attention_required', $manualFailure->fresh()->status);
        $this->assertTrue((bool) $manualFailure->fresh()->needs_user_at_system);

        $resumeFailure = $this->developmentFailure();
        $this->postSlackInteraction("dev_resume_after_manual_fix:{$resumeFailure->id}")
            ->assertOk()
            ->assertJsonPath('response_type', 'ephemeral');
        $this->assertSame('fixing', $resumeFailure->fresh()->status);
        $this->assertDatabaseHas('miriam_development_job_events', [
            'development_job_id' => $resumeFailure->development_job_id,
            'event_type' => 'manual_validation_requested',
        ]);

        $rollbackFailure = $this->developmentFailure();
        $this->postSlackInteraction("dev_rollback_phase:{$rollbackFailure->id}")
            ->assertOk()
            ->assertJsonPath('response_type', 'ephemeral');
        $this->assertSame('fixing', $rollbackFailure->fresh()->status);
        $this->assertDatabaseHas('miriam_development_job_events', [
            'development_job_id' => $rollbackFailure->development_job_id,
            'event_type' => 'rollback_requested',
        ]);
    }

    public function test_slack_interaction_payload_can_stop_job_safely(): void
    {
        $this->seedSlackContext();
        $runner = $this->runner('mra_valid', ['status' => 'active']);
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();
        app(MiriamDevelopmentManagerService::class)->submitPhaseResult($job, $job->phaseRuns()->firstOrFail(), $runner, $this->onePhasePayload(validation: ['npm_run_build' => 'failed'], status: 'failed'));

        $this->postSlackInteraction("dev_stop_job:{$job->id}")
            ->assertOk()
            ->assertJsonPath('response_type', 'ephemeral');

        $this->assertSame('cancelled', $job->fresh()->status);
    }

    public function test_slack_action_ids_parse_correctly(): void
    {
        $parser = app(\App\Services\Slack\SlackCommandParser::class);

        $this->assertSame(['action' => 'dev_apply_fix', 'failure_id' => 12], $parser->parseMiriamPromptCommand('/miriam dev_apply_fix:12'));
        $this->assertSame(['action' => 'dev_show_error', 'failure_id' => 13], $parser->parseMiriamPromptCommand('/miriam dev_show_error:13'));
        $this->assertSame(['action' => 'dev_manual_fix', 'failure_id' => 14], $parser->parseMiriamPromptCommand('/miriam dev_manual_fix:14'));
        $this->assertSame(['action' => 'dev_resume_after_manual_fix', 'failure_id' => 15], $parser->parseMiriamPromptCommand('/miriam dev_resume_after_manual_fix:15'));
        $this->assertSame(['action' => 'dev_rollback_phase', 'failure_id' => 16], $parser->parseMiriamPromptCommand('/miriam dev_rollback_phase:16'));
        $this->assertSame(['action' => 'dev_stop_job', 'job_id' => 17], $parser->parseMiriamPromptCommand('/miriam dev_stop_job:17'));
        $this->assertSame(['action' => 'dev_approve_job', 'job_id' => 18], $parser->parseMiriamPromptCommand('/miriam dev_approve_job:18'));
        $this->assertSame(['action' => 'dev_approve_job', 'job_id' => 19], $parser->parseMiriamPromptCommand('/miriam dev approve job 19'));
        $this->assertSame(['action' => 'dev_approve_job', 'job_id' => 20], $parser->parseMiriamPromptCommand('/miriam dev approve 20'));
        $this->assertSame(['action' => 'dev_go_multi'], $parser->parseMiriamPromptCommand('/miriam dev go multi'));
        $this->assertSame(['action' => 'dev_pause'], $parser->parseMiriamPromptCommand('/miriam dev pause'));
        $this->assertSame(['action' => 'dev_resume'], $parser->parseMiriamPromptCommand('/miriam dev resume'));
    }

    public function test_create_test_failure_command_creates_safe_fake_failure(): void
    {
        $this->artisan('miriam:dev:create-test-failure', ['--auto-fix' => true])
            ->expectsOutputToContain('Miriam development test failure created.')
            ->assertExitCode(0);

        $failure = MiriamDevelopmentFailure::firstOrFail();

        $this->assertSame('open', $failure->status);
        $this->assertSame('test_failed', $failure->failure_type);
        $this->assertTrue((bool) $failure->can_auto_fix);
        $this->assertDatabaseHas('miriam_development_jobs', [
            'id' => $failure->development_job_id,
            'status' => 'waiting_for_manual_fix',
            'run_mode' => 'slack_callback_test',
        ]);
    }

    public function test_close_test_failures_command_stops_artifacts_without_deleting(): void
    {
        $this->artisan('miriam:dev:create-test-failure', ['--auto-fix' => true])->assertExitCode(0);
        $failure = MiriamDevelopmentFailure::firstOrFail();
        $job = $failure->job;

        $this->artisan('miriam:dev:close-test-failures')
            ->expectsOutputToContain('Closed 1 Miriam development test failure artifact')
            ->assertExitCode(0);

        $this->assertDatabaseHas('miriam_development_failures', [
            'id' => $failure->id,
            'status' => 'stopped',
        ]);
        $this->assertDatabaseHas('miriam_development_jobs', [
            'id' => $job->id,
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('miriam_development_job_events', [
            'development_job_id' => $job->id,
            'event_type' => 'test_failure_closed',
        ]);
    }

    public function test_runner_example_config_requires_explicit_real_execution_flags(): void
    {
        $config = json_decode(file_get_contents(base_path('tools/miriam-runner/runner-config.example.json')), true);

        $this->assertFalse($config['real_execution_enabled']);
        $this->assertFalse($config['run_codex']);
        $this->assertFalse($config['fake_codex_mode']);
        $this->assertTrue($config['stop_after_one_phase']);
        $this->assertSame(1, $config['max_phases_per_invocation']);
        $this->assertFalse($config['allow_destructive_commands']);
        $this->assertFalse($config['allow_deploy']);
        $this->assertFalse($config['allow_git']);
        $this->assertFalse($config['multi_phase_enabled']);
        $this->assertSame('codex.cmd', $config['codex_binary']);
        $this->assertFalse($config['release_packaging_enabled']);
        $this->assertContains('.env', $config['release_exclude_patterns']);
    }

    public function test_release_package_can_be_requested_only_for_completed_app_job(): void
    {
        [$job] = $this->completedAppJob();

        $package = app(MiriamReleasePackageService::class)->requestForJob($job);

        $this->assertSame('packaging', $package->status);
        $this->assertSame($job->id, $package->development_job_id);
        $this->assertDatabaseHas('miriam_development_job_events', [
            'development_job_id' => $job->id,
            'event_type' => 'release_package_requested',
        ]);

        $activeJob = $this->completedAppJob(complete: false)[0];
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(MiriamReleasePackageService::class)->requestForJob($activeJob);
    }

    public function test_failed_job_cannot_create_release_package(): void
    {
        [$job] = $this->completedAppJob(complete: false);
        $job->update(['status' => 'failed']);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(MiriamReleasePackageService::class)->requestForJob($job);
    }

    public function test_runner_can_submit_release_package_result_and_approval_can_be_decided(): void
    {
        [$job] = $this->completedAppJob();
        $package = app(MiriamReleasePackageService::class)->requestForJob($job);

        $this->withToken('mra_valid')
            ->getJson("/api/runner/jobs/{$job->id}/instruction")
            ->assertOk()
            ->assertJsonPath('instruction.action', 'create_release_package')
            ->assertJsonPath('instruction.release_package_id', $package->id);

        $this->withToken('mra_valid')
            ->postJson("/api/runner/releases/{$package->id}/started")
            ->assertOk()
            ->assertJsonPath('release_package.status', 'packaging');

        $this->withToken('mra_valid')
            ->postJson("/api/runner/releases/{$package->id}/result", $this->releasePayload())
            ->assertOk()
            ->assertJsonPath('release_package.status', 'approval_required')
            ->assertJsonPath('release_package.approval_status', 'pending');

        $package->refresh();
        $this->assertSame('approval_required', $package->status);
        $this->assertSame(['app/Example.php'], $package->filesIncluded());
        $this->assertDatabaseHas('miriam_release_approvals', [
            'release_package_id' => $package->id,
            'status' => 'pending',
        ]);

        app(MiriamReleasePackageService::class)->approve($package->fresh());
        $this->assertSame('approved', $package->fresh()->status);

        app(MiriamReleasePackageService::class)->reject($package->fresh());
        $this->assertSame('rejected', $package->fresh()->status);
    }

    public function test_runner_cannot_update_another_runners_release_package(): void
    {
        [$job] = $this->completedAppJob();
        $this->runner('mra_other', ['status' => 'active', 'slug' => 'runner-other', 'name' => 'Other Runner']);
        $package = app(MiriamReleasePackageService::class)->requestForJob($job);

        $this->withToken('mra_other')
            ->postJson("/api/runner/releases/{$package->id}/result", $this->releasePayload())
            ->assertForbidden();
    }

    public function test_release_package_manifest_with_env_is_rejected(): void
    {
        [$job] = $this->completedAppJob();
        $package = app(MiriamReleasePackageService::class)->requestForJob($job);

        $this->withToken('mra_valid')
            ->postJson("/api/runner/releases/{$package->id}/result", $this->releasePayload([
                'manifest_json' => [['path' => '.env', 'size' => 10, 'hash' => 'secret']],
                'files_included_json' => ['.env'],
            ]))
            ->assertOk()
            ->assertJsonPath('release_package.status', 'failed');

        $this->assertStringContainsString('unsafe file', $package->fresh()->error_message);
    }

    public function test_slack_release_commands_work_without_deploying(): void
    {
        $this->seedSlackContext();
        [$job] = $this->completedAppJob();

        $this->postSlackSlash('release create job '.$job->id)
            ->assertOk()
            ->assertJsonPath('response_type', 'ephemeral')
            ->assertSee('release package requested', false)
            ->assertSee('Deployment was not started', false);
        $package = MiriamReleasePackage::latest()->firstOrFail();

        $this->postSlackSlash('release status '.$package->id)
            ->assertOk()
            ->assertSee('Deployment: not automated', false);

        $package->update(['status' => 'approval_required']);
        $package->approvals()->create([
            'managed_app_id' => $package->managed_app_id,
            'development_job_id' => $package->development_job_id,
            'status' => 'pending',
            'risk_level' => 'medium',
            'title' => 'Approve test release',
            'requested_at' => now(),
        ]);

        $this->postSlackSlash('release approve '.$package->id)->assertOk();
        $this->assertSame('approved', $package->fresh()->status);

        $this->postSlackSlash('release reject '.$package->id)->assertOk();
        $this->assertSame('rejected', $package->fresh()->status);
    }

    public function test_no_deployment_route_or_action_exists_for_release_packages(): void
    {
        $routes = collect(app('router')->getRoutes())->map(fn ($route) => $route->uri())->implode("\n");

        $this->assertStringNotContainsString('deploy', $routes);
        $this->assertFalse(method_exists(MiriamReleasePackageService::class, 'deploy'));
    }

    public function test_release_package_resources_include_qa_checklist(): void
    {
        [$job] = $this->completedAppJob();
        $runner = $job->runnerAgent;
        $package = app(MiriamReleasePackageService::class)->requestForJob($job->fresh(['managedApp', 'runnerAgent']));
        $package = app(MiriamReleasePackageService::class)->recordResult($package, $runner, $this->releasePayload());

        $checklist = app(MiriamReleasePackageService::class)->qaChecklist($package);

        $this->assertTrue(collect($checklist)->contains(fn (array $item) => $item['label'] === 'Validation result recorded' && $item['status'] === 'passed'));
        $this->assertTrue(collect($checklist)->contains(fn (array $item) => $item['label'] === 'Manual deployment note'));

        config([
            'services.slack.signing_secret' => 'secret',
            'services.slack.default_channel' => 'C123',
            'services.slack.allowed_user_id' => 'U123',
            'services.slack.bot_token' => 'xoxb-test',
        ]);
        Http::fake(['slack.com/*' => Http::response(['ok' => true, 'channel' => 'C123', 'ts' => '123.456'])]);

        $this->postSlackSlash('release status '.$package->id)
            ->assertOk()
            ->assertJsonPath('response_type', 'ephemeral');
    }

    public function test_runner_status_warns_about_duplicate_active_runners(): void
    {
        $this->runner('mra_runner_one', [
            'slug' => 'runner-one',
            'name' => 'Runner One',
            'machine_name' => 'MAIN-PC',
            'status' => 'active',
            'last_seen_at' => now(),
        ]);
        $this->runner('mra_runner_two', [
            'slug' => 'runner-two',
            'name' => 'Runner Two',
            'machine_name' => 'MAIN-PC',
            'status' => 'active',
            'last_seen_at' => now(),
        ]);

        $text = app(MiriamRunnerMonitoringService::class)->runnerStatusText();

        $this->assertStringContainsString('Warning: 2 active/online runners appear to be on MAIN-PC', $text);
    }

    public function test_smart_notification_policy_deduplicates_and_sends_allowed_lifecycle_events(): void
    {
        $this->seedSlackContext();
        Cache::flush();

        $service = app(MiriamSmartSlackNotificationService::class);
        $service->notifyPhasePassed('ChurchForce', 'QA', 10);
        $service->notifyPhasePassed('ChurchForce', 'QA', 10);
        $service->notifyQueueCompleted('ChurchForce and CatererHQ queues finished.', 'churchforce', 10);
        $service->notify('validation_command', 'php artisan test line output', ['app' => 'churchforce']);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains((string) (json_decode((string) $request->body(), true)['text'] ?? ''), 'Miriam phase passed: ChurchForce / QA'));
        Http::assertSent(fn ($request) => str_contains((string) (json_decode((string) $request->body(), true)['text'] ?? ''), 'Miriam queue completed.'));
    }

    public function test_blocked_development_notifications_are_sent_once(): void
    {
        $this->seedSlackContext();
        Cache::flush();

        $service = app(MiriamSmartSlackNotificationService::class);
        $service->notifyDevelopmentBlocked('ChurchForce', 'Core Completion', 'Validation failed after 3 auto-repair attempts.', 42);
        $service->notifyDevelopmentBlocked('ChurchForce', 'Core Completion', 'Validation failed after 3 auto-repair attempts.', 42);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'Miriam development blocker')
            && str_contains((string) $request->body(), 'Validation failed after 3 auto-repair attempts.'));
    }

    public function test_development_ledger_dashboard_shows_completed_current_due_and_blockers(): void
    {
        $this->artisan('miriam:apps:seed-defaults')->assertExitCode(0);
        $app = MiriamManagedApp::where('slug', 'churchforce')->firstOrFail();

        MiriamDevelopmentLedger::create([
            'app_id' => $app->id,
            'app_name' => $app->name,
            'master_vision_reference' => 'ChurchForce master vision',
            'status' => 'completed',
            'summary' => 'Validation baseline completed.',
            'next_action' => 'Run demo QA.',
            'completed_at' => now(),
        ]);
        MiriamDevelopmentLedger::create([
            'app_id' => $app->id,
            'app_name' => $app->name,
            'status' => 'running',
            'summary' => 'Website polish running.',
            'next_action' => 'Finish template review.',
        ]);
        MiriamDevelopmentLedger::create([
            'app_id' => $app->id,
            'app_name' => $app->name,
            'status' => 'blocked',
            'summary' => 'Release package blocked.',
            'blocker_reason' => 'Human product decision needed.',
        ]);

        $dashboard = app(\App\Services\MiriamDevelopmentLedgerService::class)->appDashboard($app);

        $this->assertSame('ChurchForce', $dashboard['app_name']);
        $this->assertNotEmpty($dashboard['completed_work']);
        $this->assertNotEmpty($dashboard['current_work']);
        $this->assertNotEmpty($dashboard['blockers']);
        $this->assertStringContainsString('Human product decision', $dashboard['blockers'][0]['summary']);
    }

    public function test_historical_ledger_replay_does_not_send_slack_summary(): void
    {
        $this->seedSlackContext();
        Cache::flush();
        $runner = $this->runner('mra_historical_ledger', ['status' => 'active', 'last_seen_at' => now()]);
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram(options: ['runner_agent_id' => $runner->id]);
        $phaseRun = $job->phaseRuns()->firstOrFail();
        $ledgerService = app(\App\Services\MiriamDevelopmentLedgerService::class);

        $ledger = $ledgerService->recordJob($job->fresh(), 'completed', 'Backfilled historical completion.', $phaseRun);
        $oldTime = $ledgerService->quietModeEnabledAt()->subDay();
        $ledger->forceFill([
            'created_at' => $oldTime,
            'completed_at' => $oldTime,
        ])->saveQuietly();

        $result = $ledgerService->notifySummaryIfNeeded($ledger);

        $this->assertFalse($result['sent']);
        $this->assertSame('historical_ledger_suppressed', $result['reason']);
        $this->assertNull($ledger->fresh()->summary_notified_at);
        Http::assertNothingSent();
    }

    public function test_completed_ledger_summary_notifies_once_with_durable_dedupe_key(): void
    {
        $this->seedSlackContext();
        Cache::flush();
        $runner = $this->runner('mra_durable_summary', ['status' => 'active', 'last_seen_at' => now()]);
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram(options: ['runner_agent_id' => $runner->id]);
        $phaseRun = $job->phaseRuns()->firstOrFail();
        $ledgerService = app(\App\Services\MiriamDevelopmentLedgerService::class);

        $first = $ledgerService->recordJob($job->fresh(), 'completed', 'Completed the same phase summary.', $phaseRun);
        $second = $ledgerService->recordJob($job->fresh(), 'completed', 'Completed the same phase summary.', $phaseRun);

        $firstResult = $ledgerService->notifySummaryIfNeeded($first);
        Cache::flush();
        $secondResult = $ledgerService->notifySummaryIfNeeded($second);

        $this->assertTrue($firstResult['sent']);
        $this->assertFalse($secondResult['sent']);
        $this->assertSame('durable_duplicate_suppressed', $secondResult['reason']);
        $this->assertSame($first->fresh()->notification_dedupe_key, $second->fresh()->notification_dedupe_key);
        $this->assertNotNull($first->fresh()->summary_notified_at);
        $this->assertNull($second->fresh()->summary_notified_at);
        Http::assertSentCount(1);
    }

    public function test_old_waiting_approval_cards_are_suppressed_after_quiet_mode(): void
    {
        $this->seedSlackContext();
        Cache::flush();
        $this->runner('mra_old_waiting_gate', ['status' => 'active', 'last_seen_at' => now()]);
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();
        $job->update([
            'status' => 'waiting_for_manual_fix',
            'error_message' => 'Normal stale manual-fix gate.',
        ]);
        $job->forceFill([
            'created_at' => app(\App\Services\MiriamDevelopmentLedgerService::class)->quietModeEnabledAt()->subHour(),
        ])->saveQuietly();

        $result = app(\App\Services\MiriamDevelopmentApprovalNotifier::class)->notifyJobNeedsAttention($job->fresh(['managedApp', 'runnerAgent']));

        $this->assertFalse($result['sent']);
        $this->assertSame('old_quiet_mode_gate_suppressed', $result['reason']);
        Http::assertNothingSent();
    }

    public function test_stale_approval_notices_can_be_archived_without_closing_gate(): void
    {
        $this->runner('mra_stale_notice', ['status' => 'active', 'last_seen_at' => now()]);
        $this->promptProgram();
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();
        $job->update([
            'status' => 'waiting_for_approval',
        ]);
        $job->forceFill(['updated_at' => now()->subHours(30)])->saveQuietly();

        $this->artisan('miriam:dev:archive-stale-approvals', ['--older-than-hours' => 24])
            ->expectsOutputToContain('Archived 1 stale Miriam approval/manual-fix notice')
            ->assertExitCode(0);

        $this->assertSame('waiting_for_approval', $job->fresh()->status);
        $this->assertDatabaseHas('miriam_development_job_events', [
            'development_job_id' => $job->id,
            'event_type' => 'stale_approval_notice_archived',
        ]);
    }

    public function test_stale_approval_archive_command_is_safe_when_job_table_is_missing(): void
    {
        Schema::shouldReceive('hasTable')
            ->andReturnUsing(fn (string $table): bool => $table !== 'miriam_development_jobs');

        $this->artisan('miriam:dev:archive-stale-approvals')
            ->expectsOutputToContain('miriam_development_jobs table is missing')
            ->expectsOutputToContain('Job gates and audit history were preserved.')
            ->assertExitCode(0);
    }

    public function test_development_summary_command_is_safe_when_ledger_table_is_missing(): void
    {
        Schema::shouldReceive('hasTable')
            ->andReturnUsing(fn (string $table): bool => $table !== 'miriam_development_ledgers');

        $this->artisan('miriam:dev:summary')
            ->expectsOutputToContain('Miriam Development Manager summary')
            ->expectsOutputToContain('miriam_development_ledgers')
            ->expectsOutputToContain('Ledger: not installed.')
            ->assertExitCode(0);
    }

    public function test_development_summary_command_outputs_ledger_activity(): void
    {
        $app = MiriamManagedApp::create([
            'name' => 'ChurchForce',
            'slug' => 'churchforce',
            'status' => 'active',
        ]);

        MiriamDevelopmentLedger::create([
            'app_id' => $app->id,
            'app_name' => $app->name,
            'status' => 'completed',
            'summary' => 'ChurchForce demo polish completed.',
            'test_result' => 'passed',
            'next_action' => 'Create manual release package.',
            'completed_at' => now(),
        ]);

        $this->artisan('miriam:dev:summary', ['--app' => 'churchforce'])
            ->expectsOutputToContain('Miriam Development Manager summary')
            ->expectsOutputToContain('Ledger records: 1')
            ->expectsOutputToContain('ChurchForce demo polish completed.')
            ->expectsOutputToContain('Create manual release package.')
            ->assertExitCode(0);
    }

    public function test_app_vision_sync_command_writes_idempotent_ledger_rows(): void
    {
        $app = MiriamManagedApp::create([
            'name' => 'CatererHQ',
            'slug' => 'catererhq',
            'status' => 'active',
            'notes' => 'CatererHQ demo-ready vision',
            'config_json' => json_encode([
                'development_focus' => [
                    'next_action' => 'Run final demo QA.',
                ],
            ]),
        ]);

        $this->artisan('miriam:sync-app-visions')
            ->expectsOutputToContain('Synced 1 app vision record')
            ->assertExitCode(0);

        $this->artisan('miriam:sync-app-visions')->assertExitCode(0);

        $this->assertSame(1, MiriamDevelopmentLedger::where('app_id', $app->id)
            ->where('summary', 'App vision synced for CatererHQ.')
            ->count());
        $this->assertDatabaseHas('miriam_development_ledgers', [
            'app_id' => $app->id,
            'master_vision_reference' => 'CatererHQ demo-ready vision',
            'next_action' => 'Run final demo QA.',
        ]);
    }

    public function test_ingest_summary_command_is_idempotent_and_does_not_notify_slack(): void
    {
        $this->seedSlackContext();
        Http::fake();
        $app = MiriamManagedApp::create([
            'name' => 'ChurchForce',
            'slug' => 'churchforce',
            'status' => 'active',
        ]);

        $payload = [
            '--app' => 'churchforce',
            '--job-id' => 100,
            '--phase-id' => 200,
            '--summary' => 'Ingested command center summary.',
            '--file' => ['app/Example.php'],
            '--test' => ['php artisan test'],
            '--test-result' => 'passed',
            '--commit' => 'abc1234',
            '--next' => 'Review in dashboard.',
        ];

        $this->artisan('miriam:dev:ingest-summary', $payload)
            ->expectsOutputToContain('Ingested Miriam development ledger summary')
            ->expectsOutputToContain('Slack notification was not sent')
            ->assertExitCode(0);
        $this->artisan('miriam:dev:ingest-summary', $payload)->assertExitCode(0);

        $this->assertSame(1, MiriamDevelopmentLedger::where('app_id', $app->id)
            ->where('summary', 'Ingested command center summary.')
            ->count());
        Http::assertNothingSent();
    }

    public function test_quiet_development_mode_suppresses_normal_approval_cards(): void
    {
        $this->seedSlackContext();
        Cache::flush();
        $this->runner('mra_quiet_notice', ['status' => 'active', 'last_seen_at' => now()]);
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();
        $job->update([
            'status' => 'waiting_for_approval',
            'error_message' => 'Validation passed and normal review is available.',
        ]);

        $result = app(\App\Services\MiriamDevelopmentApprovalNotifier::class)->notifyJobNeedsAttention($job->fresh(['managedApp', 'runnerAgent']));

        $this->assertFalse($result['sent']);
        $this->assertSame('quiet_development_mode', $result['reason']);
        Http::assertNothingSent();
    }

    public function test_hard_safety_blocker_notifies_immediately(): void
    {
        $this->seedSlackContext();
        Cache::flush();

        app(MiriamSmartSlackNotificationService::class)
            ->notifyHardSafetyBlocker('ChurchForce', 'Core Completion', 'Destructive database command requested.', 10);

        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'Miriam hard safety blocker')
            && str_contains((string) $request->body(), 'Destructive database command requested'));
    }

    public function test_auto_fixable_failures_do_not_notify_before_three_attempts(): void
    {
        $this->seedSlackContext();
        Cache::flush();
        $failure = $this->developmentFailure();

        Http::fake(['slack.com/*' => Http::response(['ok' => true, 'channel' => 'C123', 'ts' => '999.000'])]);
        Cache::flush();

        app(\App\Services\MiriamDevelopmentApprovalNotifier::class)
            ->notifyFailureNeedsAttention($failure->fresh(['job.managedApp', 'phaseRun.phase', 'fixAttempts']));

        Http::assertSentCount(0);

        foreach ([1, 2, 3] as $attempt) {
            MiriamDevelopmentFixAttempt::create([
                'development_failure_id' => $failure->id,
                'development_job_id' => $failure->development_job_id,
                'phase_run_id' => $failure->phase_run_id,
                'runner_agent_id' => $failure->runner_agent_id,
                'attempt_number' => $attempt,
                'status' => 'failed',
                'fix_prompt' => 'Safe auto-fix attempt.',
                'error_message' => 'Validation still failed.',
            ]);
        }

        $failure->update(['status' => 'failed']);
        app(\App\Services\MiriamDevelopmentApprovalNotifier::class)
            ->notifyFailureNeedsAttention($failure->fresh(['job.managedApp', 'phaseRun.phase', 'fixAttempts']));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'Miriam phase blocked after auto-repair attempts')
            && str_contains((string) $request->body(), 'Auto-fix attempts')
            && str_contains((string) $request->body(), '3\\/3'));
    }

    public function test_natural_language_slack_status_and_app_queries_are_read_only(): void
    {
        $this->seedSlackContext();
        $this->runner('mra_valid', ['status' => 'active', 'last_seen_at' => now()]);
        $this->artisan('miriam:apps:seed-defaults')->assertExitCode(0);

        $this->postSlack('what is Codex doing?')->assertOk();
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'Current status')
            && str_contains((string) $request->body(), 'Active jobs'));

        $this->postSlack('status of ChurchForce')->assertOk();
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'Miriam app status: ChurchForce'));
    }

    public function test_natural_language_start_task_creates_pending_confirmation_not_job(): void
    {
        $this->seedSlackContext();
        $this->runner('mra_valid', ['status' => 'active', 'last_seen_at' => now()]);
        $this->artisan('miriam:apps:seed-defaults')->assertExitCode(0);

        $this->postSlack('start next safe ChurchForce task')->assertOk();

        $this->assertSame(1, MiriamSlackPendingConfirmation::where('intended_action', 'start_next_safe_task')->count());
        $this->assertSame(0, MiriamDevelopmentJob::whereHas('managedApp', fn ($query) => $query->where('slug', 'churchforce'))->count());
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'Confirm')
            && str_contains((string) $request->body(), 'Cancel')
            && str_contains((string) $request->body(), 'miriam_confirm:'));
    }

    public function test_confirm_button_starts_pending_natural_language_action(): void
    {
        $this->seedSlackContext();
        $runner = $this->runner('mra_valid', ['status' => 'active', 'last_seen_at' => now()]);
        $this->artisan('miriam:apps:seed-defaults')->assertExitCode(0);
        MiriamManagedApp::where('slug', 'churchforce')->firstOrFail()->update([
            'local_project_path' => base_path(),
            'local_url' => 'http://taskflow.test',
            'default_runner_agent_id' => $runner->id,
        ]);

        $this->postSlack('start next safe ChurchForce task')->assertOk();
        $pending = MiriamSlackPendingConfirmation::firstOrFail();

        $this->postSlackInteraction("miriam_confirm:{$pending->id}")->assertOk();

        $this->assertSame('confirmed', $pending->fresh()->status);
        $this->assertSame(1, MiriamDevelopmentJob::whereHas('managedApp', fn ($query) => $query->where('slug', 'churchforce'))->count());
    }

    public function test_cancel_button_cancels_pending_natural_language_action(): void
    {
        $this->seedSlackContext();
        $this->runner('mra_valid', ['status' => 'active', 'last_seen_at' => now()]);

        $this->postSlack('pause everything')->assertOk();
        $pending = MiriamSlackPendingConfirmation::firstOrFail();

        $this->postSlackInteraction("miriam_cancel:{$pending->id}")->assertOk();

        $this->assertSame('cancelled', $pending->fresh()->status);
        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'Pause active Miriam development jobs'));
    }

    public function test_low_confidence_natural_language_asks_for_clarification(): void
    {
        $this->seedSlackContext();

        $this->postSlack('Miriam, make it nice')->assertOk();

        Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'Which one did you mean?'));
    }

    public function test_core_miriam_slash_commands_still_route(): void
    {
        $this->seedSlackContext();
        $this->runner('mra_valid', ['status' => 'active', 'last_seen_at' => now()]);
        $this->artisan('miriam:apps:seed-defaults')->assertExitCode(0);
        $app = MiriamManagedApp::where('slug', 'churchforce')->firstOrFail();
        MiriamDevelopmentLedger::create([
            'app_id' => $app->id,
            'app_name' => $app->name,
            'status' => 'completed',
            'summary' => 'Demo QA completed.',
            'next_action' => 'Create manual release package.',
            'completed_at' => now(),
        ]);

        $this->postSlackSlash('dev summary')
            ->assertOk()
            ->assertJsonPath('response_type', 'ephemeral')
            ->assertSee('Miriam Development Manager summary');

        $this->postSlackSlash('dev monitor')
            ->assertOk()
            ->assertSee('Miriam Development Manager monitor');

        $this->postSlackSlash('app status churchforce')
            ->assertOk()
            ->assertSee('Miriam app status: ChurchForce')
            ->assertSee('Completed work:');

        $this->postSlackSlash('blockers')
            ->assertOk()
            ->assertSee('No active Miriam development blockers');

        $this->postSlackSlash('next')
            ->assertOk()
            ->assertSee('Create manual release package');

        $this->postSlackSlash('completed today')
            ->assertOk()
            ->assertSee('Demo QA completed');

        $this->postSlackSlash('something unknown')
            ->assertOk()
            ->assertSee('Miriam Prompt OS commands');
    }

    public function test_sprint_plan_command_seeds_and_summarizes_plan(): void
    {
        $this->artisan('miriam:sprint-plan')
            ->expectsOutputToContain('Miriam 30-day build sprint plan')
            ->assertExitCode(0);

        $this->assertDatabaseHas('miriam_prompt_programs', ['slug' => 'miriam-30-day-build-sprint-plan']);
        $this->assertDatabaseHas('miriam_prompt_phases', ['phase_key' => 'week_1_churchforce_baseline']);
    }

    private function promptProgram(bool $includeNextPhase = false, ?string $slug = null): MiriamPromptProgram
    {
        $slug ??= 'miriam-product-build';

        $program = MiriamPromptProgram::create([
            'name' => 'Miriam Product Build',
            'slug' => $slug,
            'status' => 'active',
            'sort_order' => 1,
        ]);

        $phase = MiriamPromptPhase::create([
            'prompt_program_id' => $program->id,
            'phase_key' => 'phase_3d_development_manager_cloud_runner_foundation',
            'title' => 'Development Manager Cloud Runner Foundation',
            'status' => 'ready',
            'sort_order' => 1,
        ]);

        MiriamSavedPrompt::create([
            'prompt_program_id' => $program->id,
            'prompt_phase_id' => $phase->id,
            'type' => 'build',
            'title' => 'Build prompt: Development Manager Foundation',
            'body' => 'Build Phase 3D safely. Phase: {{phase_key}}',
            'status' => 'active',
            'sort_order' => 1,
        ]);

        if ($includeNextPhase) {
            $nextPhase = MiriamPromptPhase::create([
                'prompt_program_id' => $program->id,
                'phase_key' => 'phase_next',
                'title' => 'Next Phase',
                'status' => 'queued',
                'sort_order' => 2,
            ]);

            MiriamSavedPrompt::create([
                'prompt_program_id' => $program->id,
                'prompt_phase_id' => $nextPhase->id,
                'type' => 'build',
                'title' => 'Build prompt: Next Phase',
                'body' => 'Do not run automatically.',
                'status' => 'active',
                'sort_order' => 2,
            ]);
        }

        return $program;
    }

    private function runner(string $token, array $overrides = []): MiriamRunnerAgent
    {
        return MiriamRunnerAgent::create(array_merge([
            'name' => 'Runner One',
            'slug' => 'runner-one',
            'token_hash' => MiriamRunnerAgent::hashToken($token),
            'status' => 'active',
        ], $overrides));
    }

    private function seedSlackContext(): void
    {
        config([
            'services.slack.signing_secret' => 'secret',
            'services.slack.default_channel' => 'C123',
            'services.slack.allowed_user_id' => 'U123',
            'services.slack.bot_token' => 'xoxb-test',
        ]);
        Http::fake(['slack.com/*' => Http::response(['ok' => true, 'channel' => 'C123', 'ts' => '123.456'])]);
        $this->context();
        $this->promptProgram();
    }

    private function postSlack(string $text, string $user = 'U123')
    {
        $payload = json_encode(['event' => ['channel' => 'C123', 'user' => $user, 'text' => $text]]);
        $timestamp = (string) time();
        $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$payload}", 'secret');

        return $this->withHeaders([
            'X-Slack-Request-Timestamp' => $timestamp,
            'X-Slack-Signature' => $signature,
            'Content-Type' => 'application/json',
        ])->postJson(route('webhooks.slack.events'), json_decode($payload, true));
    }

    private function postSlackSlash(string $text, string $user = 'U123', string $channel = 'C123')
    {
        $body = http_build_query([
            'token' => 'legacy-token-not-used',
            'team_id' => 'T123',
            'team_domain' => 'test',
            'channel_id' => $channel,
            'channel_name' => 'codex-output',
            'user_id' => $user,
            'user_name' => 'sam',
            'command' => '/miriam',
            'text' => $text,
            'response_url' => 'https://hooks.slack.test/response',
            'trigger_id' => 'trigger.123',
        ], '', '&');
        $timestamp = (string) time();
        $signature = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", 'secret');

        return $this->call('POST', route('webhooks.slack.events'), [], [], [], [
            'HTTP_X_SLACK_REQUEST_TIMESTAMP' => $timestamp,
            'HTTP_X_SLACK_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], $body);
    }

    private function postSlackInteraction(string $actionValue, string $user = 'U123', ?string $signature = null)
    {
        $payload = json_encode([
            'type' => 'block_actions',
            'channel' => ['id' => 'C123'],
            'user' => ['id' => $user],
            'actions' => [
                [
                    'action_id' => $actionValue,
                    'value' => $actionValue,
                ],
            ],
        ]);
        $body = 'payload='.urlencode($payload);
        $timestamp = (string) time();
        $signature ??= 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$body}", 'secret');

        return $this->call('POST', route('webhooks.slack.events'), [], [], [], [
            'HTTP_X_SLACK_REQUEST_TIMESTAMP' => $timestamp,
            'HTTP_X_SLACK_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], $body);
    }

    private function developmentFailure(bool $autoFix = true): MiriamDevelopmentFailure
    {
        $this->runner('mra_failure_'.Str::random(8), [
            'status' => 'active',
            'slug' => 'runner-'.Str::lower(Str::random(8)),
            'name' => 'Runner '.Str::random(4),
        ]);
        $this->promptProgram(slug: 'miriam-product-build-'.Str::lower(Str::random(8)));
        $job = app(MiriamDevelopmentManagerService::class)->startJobFromActiveProgram();
        $runner = $job->fresh('runnerAgent')->runnerAgent;
        app(MiriamDevelopmentManagerService::class)->submitPhaseResult(
            $job,
            $job->phaseRuns()->firstOrFail(),
            $runner,
            $this->onePhasePayload(validation: ['npm_run_build' => 'failed'], status: 'failed')
        );

        $failure = MiriamDevelopmentFailure::latest()->firstOrFail();
        $failure->update(['can_auto_fix' => $autoFix]);

        return $failure->fresh(['job', 'phaseRun.phase']);
    }

    private function resultJson(string $status): string
    {
        return 'MIRIAM_RESULT_JSON: '.json_encode([
            'project' => 'miriam',
            'phase_key' => 'phase_3d_development_manager_cloud_runner_foundation',
            'status' => $status,
            'summary' => 'Dry-run result.',
            'files_changed' => ['docs/MIRIAM_DEVELOPMENT_MANAGER.md'],
            'migrations_added' => [],
            'routes_added' => [],
            'commands_added' => [],
            'tests_added' => ['tests/Feature/MiriamDevelopmentManagerTest.php'],
            'validation' => ['php_artisan_test' => 'passed'],
            'blockers' => [],
            'next_recommended_action' => 'Continue.',
        ]);
    }

    private function dryRunPayload(): array
    {
        return [
            'status' => 'dry_run_passed',
            'message' => 'Local runner dry-run reached this phase. Codex execution was intentionally skipped.',
            'codex_stdout' => '',
            'codex_stderr' => '',
            'parsed_result_json' => [
                'status' => 'review_required',
                'dry_run' => true,
            ],
            'validation_json' => [
                'runner_dry_run' => 'passed',
                'codex_execution' => 'skipped',
            ],
            'files_changed_json' => [],
        ];
    }

    private function onePhasePayload(
        array $validation = ['php_artisan_test' => 'passed', 'npm_run_build' => 'passed'],
        string $safety = 'passed',
        string $status = 'one_phase_executed',
        ?array $parsed = null,
    ): array
    {
        return [
            'status' => $status,
            'message' => 'One-phase Codex execution completed and stopped for review.',
            'codex_stdout' => 'MIRIAM_RESULT_JSON: {"status":"passed","validation":{"php_artisan_test":"passed"},"blockers":[]}',
            'codex_stderr' => '',
            'parsed_result_json' => $parsed ?? [
                'status' => $status === 'one_phase_executed' ? 'review_required' : $status,
                'one_phase_execution' => true,
                'safety_scanner' => $safety,
                'safety_risks' => $safety === 'passed' ? [] : ['DROP TABLE risk'],
            ],
            'validation_json' => $validation,
            'files_changed_json' => ['app/Example.php'],
            'backup_paths_json' => ['storage/app/backups/source.zip', 'storage/app/backups/db.sql'],
            'manifest_before_json' => [['path' => 'app/Example.php', 'hash' => 'before', 'size' => 10, 'modified_time' => '2026-06-15T00:00:00Z']],
            'manifest_after_json' => [['path' => 'app/Example.php', 'hash' => 'after', 'size' => 20, 'modified_time' => '2026-06-15T00:01:00Z']],
            'error_message' => $status === 'one_phase_executed' ? null : 'Review required.',
        ];
    }

    private function multiPhasePayload(
        array $validation = ['php_artisan_test' => 'passed', 'npm_run_build' => 'passed'],
        string $safety = 'passed',
        string $status = 'multi_phase_executed',
        string $parsedStatus = 'passed',
    ): array
    {
        return [
            'status' => $status,
            'message' => 'Controlled multi-phase Codex execution completed one phase and returned to cloud gate.',
            'codex_stdout' => 'MIRIAM_RESULT_JSON: {"status":"'.$parsedStatus.'","validation":{"php_artisan_test":"passed"},"blockers":[]}',
            'codex_stderr' => '',
            'parsed_result_json' => [
                'status' => $parsedStatus,
                'multi_phase_execution' => true,
                'safety_scanner' => $safety,
                'safety_risks' => $safety === 'passed' ? [] : ['DROP TABLE risk'],
                'blockers' => [],
            ],
            'validation_json' => $validation,
            'files_changed_json' => ['app/Example.php'],
            'backup_paths_json' => ['storage/app/backups/source.zip', 'storage/app/backups/db.sql'],
            'manifest_before_json' => [['path' => 'app/Example.php', 'hash' => 'before', 'size' => 10, 'modified_time' => '2026-06-15T00:00:00Z']],
            'manifest_after_json' => [['path' => 'app/Example.php', 'hash' => 'after', 'size' => 20, 'modified_time' => '2026-06-15T00:01:00Z']],
            'error_message' => $status === 'multi_phase_executed' ? null : 'Review required.',
        ];
    }

    private function releasePayload(array $overrides = []): array
    {
        return array_merge([
            'package_path' => 'C:\\laragon\\www\\taskflow\\storage\\app\\releases\\miriam-job-1.zip',
            'package_size_bytes' => 12345,
            'manifest_json' => [
                ['path' => 'app/Example.php', 'size' => 100, 'hash' => 'abc'],
            ],
            'files_included_json' => ['app/Example.php'],
            'files_excluded_json' => ['.env', 'vendor/autoload.php', 'node_modules/example.js'],
            'validation_summary_json' => [
                ['phase_run_id' => 1, 'status' => 'passed', 'validation' => ['php_artisan_test' => 'passed']],
            ],
        ], $overrides);
    }

    private function completedAppJob(bool $complete = true): array
    {
        MiriamRunnerAgent::firstOrCreate(
            ['slug' => 'runner-one'],
            [
                'name' => 'Runner One',
                'token_hash' => MiriamRunnerAgent::hashToken('mra_valid'),
                'status' => 'active',
            ]
        );
        $this->artisan('miriam:apps:seed-defaults')->assertExitCode(0);

        $job = app(MiriamDevelopmentManagerService::class)->startJobForApp('miriam-taskflow');
        $phaseRun = $job->phaseRuns()->orderBy('phase_order')->firstOrFail();

        if ($complete) {
            foreach ($job->phaseRuns()->orderBy('phase_order')->get() as $run) {
                app(MiriamDevelopmentManagerService::class)->submitPhaseResult(
                    $job->fresh(['phaseRuns']),
                    $run,
                    $job->runnerAgent,
                    [
                        'codex_stdout' => $this->resultJson('passed'),
                        'validation_json' => ['php_artisan_test' => 'passed'],
                        'files_changed_json' => [],
                    ]
                );
            }
        }

        return [$job->fresh(['managedApp', 'runnerAgent', 'phaseRuns']), $phaseRun->fresh()];
    }

    private function context(string $email = 'sam@example.com'): array
    {
        $user = User::factory()->create(['email' => $email]);
        $workspace = Workspace::create([
            'name' => 'Miriam Workspace '.$user->id,
            'slug' => 'miriam-workspace-'.$user->id,
            'created_by' => $user->id,
        ]);
        $workspace->users()->attach($user->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }
}
