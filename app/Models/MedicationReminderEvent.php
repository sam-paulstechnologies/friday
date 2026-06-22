<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicationReminderEvent extends Model
{
    protected $fillable = [
        'dose_log_id',
        'dose_schedule_id',
        'user_id',
        'workspace_id',
        'event_type',
        'channel',
        'device',
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

    public function log(): BelongsTo
    {
        return $this->belongsTo(MedicationDoseLog::class, 'dose_log_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(MedicationDoseSchedule::class, 'dose_schedule_id');
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
