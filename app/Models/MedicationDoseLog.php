<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicationDoseLog extends Model
{
    protected $fillable = [
        'dose_schedule_id',
        'user_id',
        'workspace_id',
        'dose_date',
        'scheduled_for',
        'scheduled_timezone',
        'status',
        'reminder_attempts',
        'first_reminded_at',
        'last_reminded_at',
        'next_reminder_at',
        'acknowledged_at',
        'acknowledgement_source',
        'acknowledgement_channel',
        'last_delivery_channel',
        'skip_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'dose_date' => 'date',
            'scheduled_for' => 'datetime',
            'first_reminded_at' => 'datetime',
            'last_reminded_at' => 'datetime',
            'next_reminder_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'metadata' => 'array',
        ];
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

    public function events(): HasMany
    {
        return $this->hasMany(MedicationReminderEvent::class, 'dose_log_id');
    }
}
