<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiskLevel extends Model
{
    public const SLUGS = ['critical', 'high', 'medium', 'low', 'none'];

    protected $fillable = [
        'slug', 'name_ar', 'name_en', 'color', 'sort_order', 'default_sla_hours',
        'requires_field_verification', 'ticket_priority', 'active',
    ];

    protected $casts = [
        'requires_field_verification' => 'boolean',
        'active' => 'boolean',
    ];

    public static function bySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->first();
    }

    public function oneAbove(): ?self
    {
        return static::where('sort_order', $this->sort_order - 1)->first();
    }
}
