<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEventMapping extends Model
{
    /** @use HasFactory<\Database\Factories\CalendarEventMappingFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'task_id',
        'project_id',
        'provider',
        'provider_event_id',
        'provider_calendar_id',
        'last_synced_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
