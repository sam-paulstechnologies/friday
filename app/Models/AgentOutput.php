<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentOutput extends Model
{
    protected $fillable = [
        'agent_run_id',
        'category',
        'detected_projects',
        'priority',
        'due_label',
        'generated_task_title',
        'suggested_next_action',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'detected_projects' => 'array',
            'payload' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }
}
