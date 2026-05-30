<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BibleTranslation extends Model
{
    protected $fillable = [
        'code',
        'name',
        'language',
        'license',
        'copyright',
        'source_url',
        'attribution',
        'is_public_domain',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_public_domain' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    public function verses(): HasMany
    {
        return $this->hasMany(BibleVerse::class);
    }
}
