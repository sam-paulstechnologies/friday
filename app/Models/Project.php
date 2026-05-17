<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectFactory> */
    use HasFactory;

    public const STATUSES = [
        'active',
        'on_hold',
        'completed',
        'archived',
    ];

    public const VISIBILITIES = [
        'workspace',
        'team',
        'private',
    ];

    protected $fillable = [
        'area_id',
        'portfolio_id',
        'workspace_id',
        'team_id',
        'owner_id',
        'name',
        'slug',
        'description',
        'status',
        'visibility',
        'start_date',
        'due_date',
        'color',
        'project_type',
        'health',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_date' => 'date',
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

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function customFieldValues(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class, 'entity_id')
            ->where('entity_type', self::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', '!=', 'archived');
    }

    public function scopeByArea($query, ?int $areaId)
    {
        return $areaId ? $query->where('area_id', $areaId) : $query;
    }

    public function scopeByPortfolio($query, ?int $portfolioId)
    {
        return $portfolioId ? $query->where('portfolio_id', $portfolioId) : $query;
    }
}
