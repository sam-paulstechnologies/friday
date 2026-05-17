<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class TaskActivity extends Model
{
    /** @use HasFactory<\Database\Factories\TaskActivityFactory> */
    use HasFactory;

    protected $fillable = [
        'task_id',
        'user_id',
        'action',
        'description',
        'old_value',
        'new_value',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
