<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    /** @use HasFactory<\Database\Factories\TaskFactory> */
    use HasFactory;

    public const STATUSES = [
        'todo',
        'in_progress',
        'blocked',
        'review',
        'completed',
        'archived',
    ];

    public const BOARD_STATUSES = [
        'todo',
        'in_progress',
        'blocked',
        'review',
        'completed',
    ];

    public const PRIORITIES = [
        'low',
        'medium',
        'high',
        'urgent',
    ];

    public const TYPES = [
        'task',
        'follow_up',
        'waiting_for',
        'decision',
        'blocker',
        'risk',
        'approval',
        'habit',
        'admin',
    ];

    public const RECURRENCE_TYPES = [
        'none',
        'daily',
        'weekly',
        'monthly',
    ];

    protected $fillable = [
        'area_id',
        'portfolio_id',
        'workspace_id',
        'project_id',
        'parent_task_id',
        'task_type',
        'context',
        'energy_level',
        'focus_score',
        'section',
        'title',
        'description',
        'status',
        'priority',
        'assignee_id',
        'reporter_id',
        'start_date',
        'due_date',
        'recurrence_type',
        'recurrence_interval',
        'recurrence_ends_at',
        'recurring_parent_id',
        'last_generated_at',
        'completed_at',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_date' => 'date',
            'recurrence_ends_at' => 'date',
            'last_generated_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parentTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    public function recurringParent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'recurring_parent_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    public function generatedOccurrences(): HasMany
    {
        return $this->hasMany(Task::class, 'recurring_parent_id');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class)->withTimestamps();
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TaskActivity::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class);
    }

    public function customFieldValues(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class, 'entity_id')
            ->where('entity_type', self::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', ['completed', 'done', 'archived']);
    }

    public function scopeByArea($query, ?int $areaId)
    {
        return $areaId ? $query->where('area_id', $areaId) : $query;
    }

    public function scopeByPortfolio($query, ?int $portfolioId)
    {
        return $portfolioId ? $query->where('portfolio_id', $portfolioId) : $query;
    }

    public function scopeDueToday($query)
    {
        return $query->whereDate('due_date', now()->toDateString());
    }

    public function scopeOverdue($query)
    {
        return $query->whereDate('due_date', '<', now()->toDateString());
    }

    public function scopeUpcoming($query, int $days = 7)
    {
        return $query->whereBetween('due_date', [now()->addDay()->toDateString(), now()->addDays($days)->toDateString()]);
    }
}
