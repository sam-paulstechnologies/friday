<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MiriamReminder extends Model
{
    protected $fillable = [
        'user_id',
        'task_id',
        'category',
        'item_type',
        'title',
        'timezone',
        'confidence',
        'due_at',
        'status',
        'reminder_attempts',
        'last_sent_at',
        'next_reminder_at',
        'completed_at',
        'cancelled_at',
        'slack_user_id',
        'slack_channel_id',
        'slack_workspace_id',
        'source_message_ts',
        'source_thread_ts',
        'source_dedupe_key',
        'google_calendar_event_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'confidence' => 'decimal:2',
            'last_sent_at' => 'datetime',
            'next_reminder_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    public function events(): HasMany
    {
        return $this->hasMany(MiriamReminderEvent::class);
    }
}
