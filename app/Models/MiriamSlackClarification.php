<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MiriamSlackClarification extends Model
{
    protected $fillable = [
        'user_id',
        'resolved_reminder_id',
        'slack_user_id',
        'slack_channel_id',
        'source_message_ts',
        'original_text',
        'clarification_question',
        'status',
        'payload',
        'expires_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expires_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolvedReminder(): BelongsTo
    {
        return $this->belongsTo(MiriamReminder::class, 'resolved_reminder_id');
    }
}
