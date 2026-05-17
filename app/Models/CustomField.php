<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomField extends Model
{
    public const FIELD_TYPES = ['text', 'number', 'date', 'select', 'boolean'];

    public const APPLIES_TO = ['project', 'task', 'both'];

    protected $fillable = [
        'workspace_id',
        'name',
        'key',
        'field_type',
        'options',
        'applies_to',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }
}
