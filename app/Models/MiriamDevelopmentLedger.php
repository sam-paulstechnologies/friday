<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MiriamDevelopmentLedger extends Model
{
    protected $fillable = [
        'app_id',
        'app_name',
        'master_vision_reference',
        'job_id',
        'phase_run_id',
        'phase_id',
        'phase_name',
        'status',
        'summary',
        'files_changed_json',
        'tests_run_json',
        'test_result',
        'commit_hash',
        'deployment_status',
        'blocker_reason',
        'next_action',
        'notification_dedupe_key',
        'summary_notified_at',
        'started_notification_dedupe_key',
        'started_notified_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'summary_notified_at' => 'datetime',
            'started_notified_at' => 'datetime',
        ];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(MiriamManagedApp::class, 'app_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(MiriamDevelopmentJob::class, 'job_id');
    }

    public function phaseRun(): BelongsTo
    {
        return $this->belongsTo(MiriamDevelopmentPhaseRun::class, 'phase_run_id');
    }

    public function filesChanged(): array
    {
        return json_decode($this->files_changed_json ?: '[]', true) ?: [];
    }

    public function testsRun(): array
    {
        return json_decode($this->tests_run_json ?: '[]', true) ?: [];
    }
}
