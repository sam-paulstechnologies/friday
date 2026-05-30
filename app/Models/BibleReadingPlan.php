<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BibleReadingPlan extends Model
{
    protected $fillable = [
        'user_id',
        'workspace_id',
        'name',
        'slug',
        'plan_type',
        'duration_days',
        'starts_on',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'is_default' => 'boolean',
        ];
    }

    public function days(): HasMany
    {
        return $this->hasMany(BibleReadingPlanDay::class)->orderBy('day_number');
    }
}
