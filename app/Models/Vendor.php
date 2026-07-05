<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vendor extends Model
{
    protected $fillable = [
        'name', 'phone', 'address', 'specialty', 'supply_duration', 'rating', 'notes', 'active',
    ];

    protected $casts = [
        'rating' => 'integer',
        'active' => 'boolean',
    ];

    public function quotations(): HasMany
    {
        return $this->hasMany(PurchaseQuotation::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
