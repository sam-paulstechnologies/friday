<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MiriamDevelopmentFailure extends Model
{
    public const TYPES = [
        'validation_failed',
        'codex_exit_failed',
        'parser_unclear',
        'safety_risk',
        'local_environment',
        'manual_browser_required',
        'migration_failed',
        'build_failed',
        'test_failed',
        'unknown',
    ];

    public const STATUSES = [
        'open',
        'fix_requested',
        'fixing',
        'fixed',
        'manual_attention_required',
        'manually_fixed',
        'rolled_back',
        'stopped',
        'failed',
    ];

    protected $fillable = [
        'development_job_id',
        'phase_run_id',
        'runner_agent_id',
        'failure_type',
        'severity',
        'title',
        'summary',
        'command',
        'file_path',
        'error_excerpt',
        'full_error_path',
        'can_auto_fix',
        'needs_user_at_system',
        'status',
        'slack_channel_id',
        'slack_message_ts',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'can_auto_fix' => 'boolean',
            'needs_user_at_system' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(MiriamDevelopmentJob::class, 'development_job_id');
    }

    public function phaseRun(): BelongsTo
    {
        return $this->belongsTo(MiriamDevelopmentPhaseRun::class, 'phase_run_id');
    }

    public function runnerAgent(): BelongsTo
    {
        return $this->belongsTo(MiriamRunnerAgent::class, 'runner_agent_id');
    }

    public function fixAttempts(): HasMany
    {
        return $this->hasMany(MiriamDevelopmentFixAttempt::class, 'development_failure_id');
    }
}
