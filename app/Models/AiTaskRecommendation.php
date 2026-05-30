<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiTaskRecommendation extends Model
{
    public const STATUSES = ['pending', 'approved', 'rejected', 'applied'];

    protected $fillable = [
        'user_id',
        'task_id',
        'recommendation_type',
        'current_value',
        'suggested_value',
        'reason',
        'confidence',
        'status',
        'source',
        'raw_prompt',
        'raw_response',
        'approved_at',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
