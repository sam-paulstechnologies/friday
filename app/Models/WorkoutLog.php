<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkoutLog extends Model
{
    protected $fillable = [
        'user_id',
        'workspace_id',
        'workout_date',
        'planned_focus',
        'actual_focus',
        'status',
        'duration_minutes',
        'intensity',
        'notes',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'workout_date' => 'date',
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
