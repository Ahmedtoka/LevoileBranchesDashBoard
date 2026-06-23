<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceItem extends Model
{
    protected $fillable = ['label', 'label_ar', 'sub_categories', 'active', 'sort_order'];

    protected $casts = ['active' => 'boolean', 'sub_categories' => 'array'];
}
