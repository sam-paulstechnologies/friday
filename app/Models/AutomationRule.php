<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRule extends Model
{
    use HasFactory;

    public const TRIGGERS = [
        'task_overdue',
        'task_due_today',
        'task_completed',
        'task_status_changed',
        'project_at_risk',
        'daily_morning_briefing',
        'daily_evening_review',
        'recurring_task_due',
    ];

    public const ACTIONS = [
        'notify_assignee',
        'notify_project_owner',
        'notify_workspace_admins',
        'move_task_to_today',
        'add_task_comment',
        'create_notification',
        'flag_project_at_risk',
    ];

    protected $fillable = [
        'workspace_id',
        'created_by',
        'name',
        'description',
        'trigger_type',
        'action_type',
        'conditions',
        'action_payload',
        'is_active',
        'last_run_at',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'action_payload' => 'array',
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }
}
