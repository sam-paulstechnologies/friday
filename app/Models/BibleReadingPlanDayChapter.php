<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BibleReadingPlanDayChapter extends Model
{
    protected $fillable = [
        'bible_reading_plan_day_id',
        'book_name',
        'book_order',
        'chapter_number',
        'position',
    ];

    public function day(): BelongsTo
    {
        return $this->belongsTo(BibleReadingPlanDay::class, 'bible_reading_plan_day_id');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(BibleReadingProgress::class, 'bible_reading_plan_day_chapter_id');
    }
}
