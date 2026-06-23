<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MiriamSlackConversationContext extends Model
{
    protected $fillable = [
        'user_id',
        'slack_user_id',
        'slack_channel_id',
        'context_type',
        'summary',
        'detail',
        'payload',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
