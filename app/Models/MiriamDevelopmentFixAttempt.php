<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MiriamDevelopmentFixAttempt extends Model
{
    public const STATUSES = ['queued', 'running', 'validation_running', 'passed', 'failed', 'blocked'];

    protected $fillable = [
        'development_failure_id',
        'development_job_id',
        'phase_run_id',
        'runner_agent_id',
        'attempt_number',
        'status',
        'fix_prompt',
        'codex_stdout',
        'codex_stderr',
        'validation_json',
        'files_changed_json',
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

    public function failure(): BelongsTo
    {
        return $this->belongsTo(MiriamDevelopmentFailure::class, 'development_failure_id');
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

    public function validation(): array
    {
        return json_decode($this->validation_json ?: '[]', true) ?: [];
    }

    public function filesChanged(): array
    {
        return json_decode($this->files_changed_json ?: '[]', true) ?: [];
    }
}
