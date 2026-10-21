<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ObservationCategory extends Model
{
    public const KINDS = ['issue', 'activity', 'assessment', 'positive'];

    protected $fillable = [
        'department_id', 'slug', 'name_ar', 'name_en', 'default_kind',
        'default_risk_level_id', 'default_responsible_department_id',
        'default_cc_department_ids', 'default_sla_hours', 'sort_order', 'active',
    ];

    protected $casts = [
        'default_cc_department_ids' => 'array',
        'active' => 'boolean',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function riskLevel(): BelongsTo
    {
        return $this->belongsTo(RiskLevel::class, 'default_risk_level_id');
    }

    public function responsibleDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'default_responsible_department_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ObservationCatalogItem::class, 'category_id');
    }
}
