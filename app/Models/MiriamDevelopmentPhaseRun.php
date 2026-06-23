<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MiriamDevelopmentPhaseRun extends Model
{
    public const STATUSES = [
        'queued',
        'assigned',
        'running',
        'output_received',
        'validation_running',
        'passed',
        'failed',
        'blocked',
        'waiting_for_approval',
        'waiting_for_manual_fix',
        'rolled_back',
        'skipped',
    ];

    protected $fillable = [
        'development_job_id',
        'managed_app_id',
        'validation_profile_id',
        'prompt_program_id',
        'prompt_phase_id',
        'saved_prompt_id',
        'runner_agent_id',
        'phase_order',
        'status',
        'prompt_body',
        'runner_instruction_json',
        'local_project_path_snapshot',
        'local_url_snapshot',
        'codex_stdout',
        'codex_stderr',
        'parsed_result_json',
        'validation_json',
        'files_changed_json',
        'backup_paths_json',
        'manifest_before_json',
        'manifest_after_json',
        'release_package_path',
        'started_at',
        'completed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(MiriamDevelopmentJob::class, 'development_job_id');
    }

    public function managedApp(): BelongsTo
    {
        return $this->belongsTo(MiriamManagedApp::class, 'managed_app_id');
    }

    public function validationProfile(): BelongsTo
    {
        return $this->belongsTo(MiriamAppValidationProfile::class, 'validation_profile_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(MiriamPromptProgram::class, 'prompt_program_id');
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(MiriamPromptPhase::class, 'prompt_phase_id');
    }

    public function savedPrompt(): BelongsTo
    {
        return $this->belongsTo(MiriamSavedPrompt::class, 'saved_prompt_id');
    }

    public function runnerAgent(): BelongsTo
    {
        return $this->belongsTo(MiriamRunnerAgent::class, 'runner_agent_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MiriamDevelopmentJobEvent::class, 'phase_run_id');
    }

    public function failures(): HasMany
    {
        return $this->hasMany(MiriamDevelopmentFailure::class, 'phase_run_id');
    }

    public function fixAttempts(): HasMany
    {
        return $this->hasMany(MiriamDevelopmentFixAttempt::class, 'phase_run_id');
    }

    public function parsedResult(): array
    {
        return json_decode($this->parsed_result_json ?: '[]', true) ?: [];
    }

    public function validation(): array
    {
        return json_decode($this->validation_json ?: '[]', true) ?: [];
    }
}
