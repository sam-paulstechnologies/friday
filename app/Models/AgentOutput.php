<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentOutput extends Model
{
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'agent_run_id',
        'agent_key',
        'agent_name',
        'context_label',
        'category',
        'title',
        'status',
        'detected_projects',
        'priority',
        'due_label',
        'generated_task_title',
        'suggested_next_action',
        'payload',
        'sent_to_today_at',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'detected_projects' => 'array',
            'payload' => 'array',
            'sent_to_today_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
