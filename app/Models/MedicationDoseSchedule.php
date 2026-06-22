<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicationDoseSchedule extends Model
{
    protected $fillable = [
        'user_id',
        'workspace_id',
        'medication_id',
        'dose_key',
        'label',
        'dosage_text',
        'timing_note',
        'schedule_time',
        'hard_deadline_time',
        'timezone',
        'active',
        'repeat_interval_minutes',
        'quiet_hours_start',
        'quiet_hours_end',
        'hide_details_in_notifications',
        'default_channel',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'hide_details_in_notifications' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MedicationDoseLog::class, 'dose_schedule_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MedicationReminderEvent::class, 'dose_schedule_id');
    }
}
