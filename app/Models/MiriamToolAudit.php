<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MiriamToolAudit extends Model
{
    protected $fillable = [
        'user_id',
        'slack_user_id',
        'slack_channel_id',
        'event_type',
        'tool_name',
        'status',
        'summary',
        'input',
        'output',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
