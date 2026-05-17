<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Decision extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'area_id', 'portfolio_id', 'project_id', 'title', 'description', 'options', 'decision', 'status', 'decision_due_date', 'decided_at'];

    protected function casts(): array
    {
        return ['options' => 'array', 'decision_due_date' => 'date', 'decided_at' => 'datetime'];
    }

    public function user() { return $this->belongsTo(User::class); }
    public function area() { return $this->belongsTo(Area::class); }
    public function portfolio() { return $this->belongsTo(Portfolio::class); }
    public function project() { return $this->belongsTo(Project::class); }
}
