<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObservationCatalogItem extends Model
{
    protected $fillable = [
        'category_id', 'code', 'label_ar', 'label_en', 'keywords', 'kind',
        'risk_level_id', 'responsible_department_id', 'cc_department_ids',
        'sla_hours', 'extra_fields', 'escalation_rules', 'usage_count',
        'sort_order', 'active',
    ];

    protected $casts = [
        'keywords' => 'array',
        'extra_fields' => 'array',
        'escalation_rules' => 'array',
        'cc_department_ids' => 'array',
        'active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ObservationCategory::class, 'category_id');
    }

    public function riskLevel(): BelongsTo
    {
        return $this->belongsTo(RiskLevel::class, 'risk_level_id');
    }

    public function responsibleDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'responsible_department_id');
    }

    public function effectiveSlaHours(): ?int
    {
        return $this->sla_hours
            ?? $this->category?->default_sla_hours
            ?? $this->riskLevel?->default_sla_hours;
    }
}
