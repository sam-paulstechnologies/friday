<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Goal extends Model
{
    use HasFactory;

    public const STATUSES = [
        'not_started',
        'on_track',
        'at_risk',
        'off_track',
        'completed',
        'archived',
    ];

    protected $fillable = [
        'workspace_id',
        'owner_id',
        'title',
        'description',
        'status',
        'target_date',
        'progress_percentage',
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function keyResults(): HasMany
    {
        return $this->hasMany(GoalKeyResult::class);
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withTimestamps();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(GoalActivity::class);
    }
}
