<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SlackWebhookEvent extends Model
{
    protected $fillable = [
        'endpoint',
        'event_id',
        'event_type',
        'outcome',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }
}
