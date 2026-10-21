<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Area extends Model
{
    protected $fillable = ['name', 'name_ar', 'slug', 'manager_id', 'sort_order', 'active'];

    protected $casts = ['active' => 'boolean'];

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class, 'area_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }
}
