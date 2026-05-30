<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpiritualNote extends Model
{
    protected $fillable = [
        'user_id',
        'workspace_id',
        'bible_reading_plan_day_id',
        'title',
        'content',
        'book_name',
        'chapter_number',
        'tags',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
        ];
    }
}
