<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyHealthLog extends Model
{
    protected $fillable = [
        'user_id',
        'workspace_id',
        'log_date',
        'sleep_hours',
        'sleep_quality',
        'energy_score',
        'mood_score',
        'gym_readiness_score',
        'gym_approved',
        'gym_recommendation',
        'workout_focus',
        'workout_notes',
        'medication_status',
        'notes',
        'source',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'log_date' => 'date',
            'sleep_hours' => 'decimal:2',
            'gym_approved' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
