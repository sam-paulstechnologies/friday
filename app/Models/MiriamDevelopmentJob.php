<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MiriamDevelopmentJob extends Model
{
    public const STATUSES = [
        'queued',
        'waiting_for_runner',
        'preparing',
        'running',
        'waiting_for_approval',
        'waiting_for_manual_fix',
        'paused',
        'blocked',
        'failed',
        'completed',
        'cancelled',
    ];

    protected $fillable = [
        'prompt_program_id',
        'managed_app_id',
        'validation_profile_id',
        'runner_agent_id',
        'started_by_user_id',
        'title',
        'status',
        'current_phase_id',
        'total_phases',
        'completed_phases',
        'failed_phase_id',
        'run_mode',
        'local_project_path_snapshot',
        'local_url_snapshot',
        'options_json',
        'started_at',
        'completed_at',
        'cancelled_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(MiriamPromptProgram::class, 'prompt_program_id');
    }

    public function managedApp(): BelongsTo
    {
        return $this->belongsTo(MiriamManagedApp::class, 'managed_app_id');
    }

    public function validationProfile(): BelongsTo
    {
        return $this->belongsTo(MiriamAppValidationProfile::class, 'validation_profile_id');
    }

    public function runnerAgent(): BelongsTo
    {
        return $this->belongsTo(MiriamRunnerAgent::class, 'runner_agent_id');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function currentPhase(): BelongsTo
    {
        return $this->belongsTo(MiriamPromptPhase::class, 'current_phase_id');
    }

    public function failedPhase(): BelongsTo
    {
        return $this->belongsTo(MiriamPromptPhase::class, 'failed_phase_id');
    }

    public function phaseRuns(): HasMany
    {
        return $this->hasMany(MiriamDevelopmentPhaseRun::class, 'development_job_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MiriamDevelopmentJobEvent::class, 'development_job_id');
    }

    public function failures(): HasMany
    {
        return $this->hasMany(MiriamDevelopmentFailure::class, 'development_job_id');
    }

    public function fixAttempts(): HasMany
    {
        return $this->hasMany(MiriamDevelopmentFixAttempt::class, 'development_job_id');
    }

    public function releasePackages(): HasMany
    {
        return $this->hasMany(MiriamReleasePackage::class, 'development_job_id');
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(MiriamDevelopmentLedger::class, 'job_id');
    }

    public function options(): array
    {
        return json_decode($this->options_json ?: '[]', true) ?: [];
    }
}
