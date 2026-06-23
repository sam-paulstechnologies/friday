<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MiriamReminder extends Model
{
    protected $fillable = [
        'user_id',
        'category',
        'title',
        'timezone',
        'due_at',
        'status',
        'reminder_attempts',
        'last_sent_at',
        'next_reminder_at',
        'completed_at',
        'cancelled_at',
        'slack_user_id',
        'slack_channel_id',
        'source_message_ts',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
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

    public function events(): HasMany
    {
        return $this->hasMany(MiriamReminderEvent::class);
    }
}
