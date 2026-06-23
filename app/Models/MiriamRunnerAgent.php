<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MiriamRunnerAgent extends Model
{
    public const STATUSES = ['inactive', 'active', 'paused', 'disabled'];

    protected $fillable = [
        'name',
        'slug',
        'owner_user_id',
        'token_hash',
        'machine_name',
        'os',
        'local_project_path',
        'status',
        'last_seen_at',
        'last_ip',
        'capabilities_json',
        'config_json',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(MiriamDevelopmentJob::class, 'runner_agent_id');
    }

    public function phaseRuns(): HasMany
    {
        return $this->hasMany(MiriamDevelopmentPhaseRun::class, 'runner_agent_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MiriamDevelopmentJobEvent::class, 'runner_agent_id');
    }

    public function failures(): HasMany
    {
        return $this->hasMany(MiriamDevelopmentFailure::class, 'runner_agent_id');
    }

    public function fixAttempts(): HasMany
    {
        return $this->hasMany(MiriamDevelopmentFixAttempt::class, 'runner_agent_id');
    }

    public function managedApps(): HasMany
    {
        return $this->hasMany(MiriamManagedApp::class, 'default_runner_agent_id');
    }

    public function capabilities(): array
    {
        return json_decode($this->capabilities_json ?: '[]', true) ?: [];
    }

    public function config(): array
    {
        return json_decode($this->config_json ?: '[]', true) ?: [];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
