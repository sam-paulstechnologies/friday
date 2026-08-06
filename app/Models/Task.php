<?php

namespace App\Models;

use App\Support\OperationalClock;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
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

    /**
     * Canonical daily-loop buckets, stored in `workflow_state`.
     *
     * These are deliberately NOT stored in `section`: that column holds the
     * operator's own grouping labels inside a project ("Phase 4 — Sales Kit",
     * "Launch Checklist"), and 424 live tasks depend on it. The two concepts
     * share no column.
     *
     * `inbox` means "captured, not yet triaged" and is the only value the
     * Inbox reads. A null workflow_state is an ordinary untriaged task and
     * behaves exactly as tasks did before this field existed.
     */
    public const WORKFLOW_INBOX = 'inbox';

    public const WORKFLOW_TODAY = 'today';

    public const WORKFLOW_THIS_WEEK = 'this_week';

    public const WORKFLOW_THIS_MONTH = 'this_month';

    public const WORKFLOW_LATER = 'later';

    public const WORKFLOW_WAITING = 'waiting';

    public const WORKFLOW_DELEGATED = 'delegated';

    public const WORKFLOW_TASKS = 'tasks';

    public const WORKFLOW_DISMISSED = 'dismissed';

    public const WORKFLOW_STATES = [
        self::WORKFLOW_INBOX,
        self::WORKFLOW_TODAY,
        self::WORKFLOW_THIS_WEEK,
        self::WORKFLOW_THIS_MONTH,
        self::WORKFLOW_LATER,
        self::WORKFLOW_WAITING,
        self::WORKFLOW_DELEGATED,
        self::WORKFLOW_TASKS,
        self::WORKFLOW_DISMISSED,
    ];

    /** Buckets a user can move an open task into from the interface. */
    public const ASSIGNABLE_WORKFLOW_STATES = [
        self::WORKFLOW_TODAY,
        self::WORKFLOW_THIS_WEEK,
        self::WORKFLOW_THIS_MONTH,
        self::WORKFLOW_LATER,
        self::WORKFLOW_WAITING,
        self::WORKFLOW_DELEGATED,
        self::WORKFLOW_TASKS,
    ];

    /** Buckets that must never appear in an active Today or task list. */
    public const INACTIVE_WORKFLOW_STATES = [
        self::WORKFLOW_INBOX,
        self::WORKFLOW_DISMISSED,
    ];

    /** User-facing wording. The interface never shows the raw value. */
    public const WORKFLOW_LABELS = [
        self::WORKFLOW_INBOX => 'Inbox',
        self::WORKFLOW_TODAY => 'Today',
        self::WORKFLOW_THIS_WEEK => 'This week',
        self::WORKFLOW_THIS_MONTH => 'This month',
        self::WORKFLOW_LATER => 'Later',
        self::WORKFLOW_WAITING => 'Waiting',
        self::WORKFLOW_DELEGATED => 'Delegated',
        self::WORKFLOW_TASKS => 'Anytime',
        self::WORKFLOW_DISMISSED => 'Dismissed',
    ];

    /** Statuses that mean the task is no longer active work. */
    public const CLOSED_STATUSES = [
        'completed',
        'done',
        'archived',
    ];

    public static function workflowLabel(?string $state): ?string
    {
        return $state === null ? null : (self::WORKFLOW_LABELS[$state] ?? null);
    }

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
        'workflow_state',
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
        'source',
        'source_dedupe_key',
        'source_metadata',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_date' => 'date',
            'recurrence_ends_at' => 'date',
            'last_generated_at' => 'datetime',
            'completed_at' => 'datetime',
            'source_metadata' => 'array',
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

    public function miriamReminders(): HasMany
    {
        return $this->hasMany(MiriamReminder::class);
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
        return $query->whereNotIn('status', self::CLOSED_STATUSES);
    }

    /** Captured but not yet triaged. The Inbox reads exactly this. */
    public function scopeInInbox($query)
    {
        return $query->where('workflow_state', self::WORKFLOW_INBOX)
            ->whereNotIn('status', self::CLOSED_STATUSES);
    }

    /** Triaged, still open, and therefore eligible for Today and task lists. */
    public function scopeTriaged($query)
    {
        return $query->where(function ($query): void {
            $query->whereNull('workflow_state')
                ->orWhereNotIn('workflow_state', self::INACTIVE_WORKFLOW_STATES);
        });
    }

    /** Tasks the operator has intentionally placed on today's plate. */
    public function scopeInToday($query)
    {
        return $query->where('workflow_state', self::WORKFLOW_TODAY);
    }

    public function scopeByArea($query, ?int $areaId)
    {
        return $areaId ? $query->where('area_id', $areaId) : $query;
    }

    public function scopeByPortfolio($query, ?int $portfolioId)
    {
        return $portfolioId ? $query->where('portfolio_id', $portfolioId) : $query;
    }

    /**
     * due_date is a calendar date, not an instant, so it is compared against
     * the operational calendar day rather than a UTC-derived one.
     */
    public function scopeDueToday($query)
    {
        return $query->whereDate('due_date', self::clock()->todayString());
    }

    public function scopeOverdue($query)
    {
        return $query->whereDate('due_date', '<', self::clock()->todayString());
    }

    public function scopeUpcoming($query, int $days = 7)
    {
        return $query->whereBetween('due_date', [
            self::clock()->dateString(1),
            self::clock()->dateString($days),
        ]);
    }

    /**
     * completed_at is a UTC timestamp, so an operational calendar day has to
     * be converted into a UTC range. whereDate() against it would report the
     * wrong day for the UTC hours that belong to the previous local day.
     */
    public function scopeCompletedOn($query, ?string $date = null)
    {
        [$start, $end] = self::clock()->dayRangeUtc($date);

        return $query->whereBetween('completed_at', [$start, $end]);
    }

    protected static function clock(): OperationalClock
    {
        return app(OperationalClock::class);
    }
}
