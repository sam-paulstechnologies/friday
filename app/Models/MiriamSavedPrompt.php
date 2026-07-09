<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MiriamSavedPrompt extends Model
{
    public const TYPES = ['build', 'fix', 'qa', 'security', 'ui_review', 'db_backup', 'git_push', 'cleanup', 'next_step'];

    protected $fillable = [
        'prompt_program_id',
        'prompt_phase_id',
        'type',
        'title',
        'body',
        'variables_json',
        'status',
        'sort_order',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(MiriamPromptProgram::class, 'prompt_program_id');
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(MiriamPromptPhase::class, 'prompt_phase_id');
    }

    public function codexRuns(): HasMany
    {
        return $this->hasMany(MiriamCodexRun::class, 'saved_prompt_id');
    }

    public function developmentPhaseRuns(): HasMany
    {
        return $this->hasMany(MiriamDevelopmentPhaseRun::class, 'saved_prompt_id');
    }
}
