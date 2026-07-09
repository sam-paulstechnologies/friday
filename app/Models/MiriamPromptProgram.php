<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MiriamPromptProgram extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'vision_markdown',
        'status',
        'sort_order',
    ];

    public function phases(): HasMany
    {
        return $this->hasMany(MiriamPromptPhase::class, 'prompt_program_id');
    }

    public function savedPrompts(): HasMany
    {
        return $this->hasMany(MiriamSavedPrompt::class, 'prompt_program_id');
    }

    public function codexRuns(): HasMany
    {
        return $this->hasMany(MiriamCodexRun::class, 'prompt_program_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(MiriamPromptDelivery::class, 'prompt_program_id');
    }

    public function developmentJobs(): HasMany
    {
        return $this->hasMany(MiriamDevelopmentJob::class, 'prompt_program_id');
    }

    public function developmentPhaseRuns(): HasMany
    {
        return $this->hasMany(MiriamDevelopmentPhaseRun::class, 'prompt_program_id');
    }

    public function managedApps(): HasMany
    {
        return $this->hasMany(MiriamManagedApp::class, 'prompt_program_id');
    }
}
