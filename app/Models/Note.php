<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Note extends Model
{
    public const TYPES = ['typed', 'handwritten', 'mixed'];

    protected $fillable = [
        'user_id',
        'workspace_id',
        'area_id',
        'portfolio_id',
        'project_id',
        'task_id',
        'spiritual_reading_day_id',
        'title',
        'content',
        'canvas_data',
        'canvas_preview_path',
        'note_type',
        'tags',
        'pinned',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'pinned' => 'boolean',
        ];
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

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
