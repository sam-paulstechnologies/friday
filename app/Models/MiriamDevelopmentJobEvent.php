<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MiriamDevelopmentJobEvent extends Model
{
    protected $fillable = [
        'development_job_id',
        'phase_run_id',
        'runner_agent_id',
        'event_type',
        'title',
        'body',
        'meta_json',
    ];

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

    public function meta(): array
    {
        return json_decode($this->meta_json ?: '[]', true) ?: [];
    }
}
