<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Approval extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'area_id', 'portfolio_id', 'project_id', 'task_id', 'title', 'description', 'requested_by', 'status', 'approved_at', 'rejected_at'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'rejected_at' => 'datetime'];
    }

    public function user() { return $this->belongsTo(User::class); }
    public function area() { return $this->belongsTo(Area::class); }
    public function portfolio() { return $this->belongsTo(Portfolio::class); }
    public function project() { return $this->belongsTo(Project::class); }
    public function task() { return $this->belongsTo(Task::class); }
}
