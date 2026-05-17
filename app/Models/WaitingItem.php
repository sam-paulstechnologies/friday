<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WaitingItem extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'area_id', 'portfolio_id', 'project_id', 'task_id', 'title', 'waiting_on', 'description', 'status', 'follow_up_date', 'closed_at'];

    protected function casts(): array
    {
        return ['follow_up_date' => 'date', 'closed_at' => 'datetime'];
    }

    public function user() { return $this->belongsTo(User::class); }
    public function area() { return $this->belongsTo(Area::class); }
    public function portfolio() { return $this->belongsTo(Portfolio::class); }
    public function project() { return $this->belongsTo(Project::class); }
    public function task() { return $this->belongsTo(Task::class); }
}
