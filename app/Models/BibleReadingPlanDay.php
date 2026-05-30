<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BibleReadingPlanDay extends Model
{
    protected $fillable = [
        'bible_reading_plan_id',
        'day_number',
        'reading_date',
    ];

    protected function casts(): array
    {
        return [
            'reading_date' => 'date',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BibleReadingPlan::class, 'bible_reading_plan_id');
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(BibleReadingPlanDayChapter::class)->orderBy('position');
    }
}
