<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BibleBook extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'testament',
        'book_order',
        'chapters_count',
    ];

    public function verses(): HasMany
    {
        return $this->hasMany(BibleVerse::class);
    }
}
