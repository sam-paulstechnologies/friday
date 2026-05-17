<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskReminder extends Model
{
    /** @use HasFactory<\Database\Factories\TaskReminderFactory> */
    use HasFactory;

    protected $fillable = [
        'task_id',
        'user_id',
        'reminder_type',
        'reminder_date',
    ];

    protected function casts(): array
    {
        return [
            'reminder_date' => 'date',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
