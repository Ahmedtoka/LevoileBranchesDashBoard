<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VisitTemplate extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'type', 'role_id', 'scored', 'active'];

    protected $casts = ['scored' => 'boolean', 'active' => 'boolean'];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ChecklistSection::class)->orderBy('sort_order');
    }

    public function questions()
    {
        return $this->hasManyThrough(ChecklistQuestion::class, ChecklistSection::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }
}
