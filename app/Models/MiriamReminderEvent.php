<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MiriamReminderEvent extends Model
{
    protected $fillable = [
        'miriam_reminder_id',
        'event_type',
        'channel',
        'occurred_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function reminder(): BelongsTo
    {
        return $this->belongsTo(MiriamReminder::class, 'miriam_reminder_id');
    }
}
