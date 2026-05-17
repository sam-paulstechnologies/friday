<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectTemplateTask extends Model
{
    protected $fillable = [
        'project_template_id',
        'title',
        'description',
        'section',
        'priority',
        'position',
        'offset_days',
    ];

    public function projectTemplate(): BelongsTo
    {
        return $this->belongsTo(ProjectTemplate::class);
    }
}
