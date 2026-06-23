<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MiriamManagedApp extends Model
{
    public const STATUSES = ['active', 'paused', 'archived'];

    protected $fillable = [
        'name',
        'slug',
        'description',
        'app_type',
        'tech_stack',
        'status',
        'prompt_program_id',
        'default_runner_agent_id',
        'local_project_path',
        'local_url',
        'cloud_url',
        'super_admin_url',
        'backup_path',
        'release_path',
        'notes',
        'config_json',
    ];

    public function promptProgram(): BelongsTo
    {
        return $this->belongsTo(MiriamPromptProgram::class, 'prompt_program_id');
    }

    public function defaultRunnerAgent(): BelongsTo
    {
        return $this->belongsTo(MiriamRunnerAgent::class, 'default_runner_agent_id');
    }

    public function validationProfiles(): HasMany
    {
        return $this->hasMany(MiriamAppValidationProfile::class, 'managed_app_id');
    }

    public function developmentJobs(): HasMany
    {
        return $this->hasMany(MiriamDevelopmentJob::class, 'managed_app_id');
    }

    public function releasePackages(): HasMany
    {
        return $this->hasMany(MiriamReleasePackage::class, 'managed_app_id');
    }

    public function developmentLedgers(): HasMany
    {
        return $this->hasMany(MiriamDevelopmentLedger::class, 'app_id');
    }

    public function activeValidationProfile(): ?MiriamAppValidationProfile
    {
        return $this->validationProfiles()
            ->where('status', 'active')
            ->orderBy('id')
            ->first();
    }

    public function config(): array
    {
        return json_decode($this->config_json ?: '[]', true) ?: [];
    }
}
