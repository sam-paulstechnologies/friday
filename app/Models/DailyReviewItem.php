<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyReviewItem extends Model
{
    protected $fillable = [
        'daily_review_id',
        'task_id',
        'position',
        'item_type',
        'snapshot_title',
        'snapshot_status',
        'snapshot_priority',
        'snapshot_due_date',
        'completed_at',
        'response_text',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function dailyReview(): BelongsTo
    {
        return $this->belongsTo(DailyReview::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
