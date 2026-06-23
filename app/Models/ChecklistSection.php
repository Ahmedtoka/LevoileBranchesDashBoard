<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChecklistSection extends Model
{
    protected $fillable = [
        'visit_template_id', 'title', 'title_ar', 'weight', 'sort_order',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(VisitTemplate::class, 'visit_template_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ChecklistQuestion::class)->orderBy('sort_order');
    }
}
