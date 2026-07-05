<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseQuotation extends Model
{
    protected $fillable = [
        'purchase_request_id', 'vendor_id', 'vendor_name',
        'unit_price', 'total_price', 'supply_duration', 'payment_terms', 'notes',
        'is_selected', 'quoted_at',
    ];

    protected $casts = [
        'unit_price' => 'float',
        'total_price' => 'float',
        'is_selected' => 'boolean',
        'quoted_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function vendorLabel(): string
    {
        return $this->vendor?->name ?? ($this->vendor_name ?: '—');
    }
}
