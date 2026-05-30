<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BibleReadingProgress extends Model
{
    protected $table = 'bible_reading_progress';

    protected $fillable = [
        'user_id',
        'workspace_id',
        'bible_reading_plan_day_chapter_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(BibleReadingPlanDayChapter::class, 'bible_reading_plan_day_chapter_id');
    }
}
