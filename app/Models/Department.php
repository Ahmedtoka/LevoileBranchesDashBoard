<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = ['slug', 'name', 'ticket_prefix', 'color', 'active', 'is_reporting'];

    protected $casts = ['active' => 'boolean', 'is_reporting' => 'boolean'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(User::class)->where('is_department_manager', false);
    }

    public function manager()
    {
        return $this->hasOne(User::class)->where('is_department_manager', true);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
