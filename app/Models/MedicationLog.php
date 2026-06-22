<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicationLog extends Model
{
    protected $fillable = [
        'medication_id',
        'user_id',
        'workspace_id',
        'log_date',
        'status',
        'confirmed_at',
        'snoozed_until',
        'notes',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'log_date' => 'date',
            'confirmed_at' => 'datetime',
            'snoozed_until' => 'datetime',
        ];
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class);
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
