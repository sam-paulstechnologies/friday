<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MiriamPromptPhase extends Model
{
    public const STATUSES = ['queued', 'ready', 'in_progress', 'blocked', 'review_required', 'passed', 'failed', 'skipped'];

    protected $fillable = [
        'prompt_program_id',
        'phase_key',
        'title',
        'description',
        'status',
        'depends_on_phase_id',
        'sort_order',
        'acceptance_criteria',
        'safety_notes',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(MiriamPromptProgram::class, 'prompt_program_id');
    }

    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'depends_on_phase_id');
    }

    public function savedPrompts(): HasMany
    {
        return $this->hasMany(MiriamSavedPrompt::class, 'prompt_phase_id');
    }

    public function codexRuns(): HasMany
    {
        return $this->hasMany(MiriamCodexRun::class, 'prompt_phase_id');
    }

    public function developmentJobs(): HasMany
    {
        return $this->hasMany(MiriamDevelopmentJob::class, 'current_phase_id');
    }

    public function developmentPhaseRuns(): HasMany
    {
        return $this->hasMany(MiriamDevelopmentPhaseRun::class, 'prompt_phase_id');
    }
}
