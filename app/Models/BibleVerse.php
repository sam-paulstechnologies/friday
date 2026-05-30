<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BibleVerse extends Model
{
    protected $fillable = [
        'bible_translation_id',
        'bible_book_id',
        'chapter_number',
        'verse_number',
        'text',
    ];

    public function translation(): BelongsTo
    {
        return $this->belongsTo(BibleTranslation::class, 'bible_translation_id');
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(BibleBook::class, 'bible_book_id');
    }
}
