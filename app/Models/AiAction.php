<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AiAction extends Model
{
    use HasFactory;

    public const STATUS_PROPOSED = 'proposed';
    public const STATUS_EXECUTED = 'executed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'user_id',
        'workspace_id',
        'action_type',
        'status',
        'target_type',
        'target_id',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }
}
