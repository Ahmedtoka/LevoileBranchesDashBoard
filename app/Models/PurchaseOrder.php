<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PurchaseOrder extends Model
{
    public const SUPPLY_AR = [
        'full' => 'تم التوريد بالكامل',
        'partial' => 'تم التوريد جزئي',
        'over' => 'تم التوريد بالزيادة',
        'none' => 'لم يتم التوريد',
    ];

    public const PAYMENT_AR = [
        'cash' => 'سداد نقدي',
        'cheque' => 'سداد بشيك',
        'credit' => 'سداد آجل',
    ];

    protected $fillable = [
        'po_number', 'purchase_request_id', 'ext_request_number', 'vendor_id', 'vendor_name',
        'issued_at', 'amount', 'expected_delivery_at', 'supply_status', 'payment_status', 'payment_terms',
    ];

    protected $casts = [
        'amount' => 'float',
        'issued_at' => 'datetime',
        'expected_delivery_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** Requests batched into this (combined) PO. */
    public function requests(): BelongsToMany
    {
        return $this->belongsToMany(PurchaseRequest::class, 'purchase_order_request');
    }

    public function vendorLabel(): string
    {
        return $this->vendor?->name ?? ($this->vendor_name ?: '—');
    }

    public function supplyAr(): string
    {
        return self::SUPPLY_AR[$this->supply_status] ?? ($this->supply_status ?? '—');
    }

    public function paymentAr(): string
    {
        return self::PAYMENT_AR[$this->payment_status] ?? ($this->payment_status ?? '—');
    }

    public static function nextNumber(): string
    {
        $last = static::orderByDesc('id')->value('po_number');
        $n = $last ? (int) preg_replace('/\D/', '', (string) $last) : 0;

        return (string) ($n + 1);
    }
}
