<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpiritualJournal extends Model
{
    protected $fillable = [
        'user_id',
        'workspace_id',
        'bible_reading_plan_day_id',
        'entry_date',
        'title',
        'content',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
        ];
    }
}
