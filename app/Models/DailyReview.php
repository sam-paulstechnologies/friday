<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyReview extends Model
{
    protected $fillable = [
        'user_id',
        'review_date',
        'type',
        'status',
        'summary',
        'slack_channel_id',
        'slack_message_ts',
        'sent_at',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'review_date' => 'date',
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DailyReviewItem::class);
    }
}
