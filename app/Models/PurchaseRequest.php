<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseRequest extends Model
{
    public const STATUSES = [
        'new', 'pricing', 'finance_review', 'po_issued', 'executing',
        'purchased', 'cancelled', 'rejected', 'postponed', 'sufficed',
    ];

    public const PRIORITIES = ['normal', 'urgent', 'monthly'];

    public const STATUS_AR = [
        'new' => 'جديد',
        'pricing' => 'جاري التسعير',
        'finance_review' => 'بانتظار موافقة المالية',
        'po_issued' => 'تم إصدار PO',
        'executing' => 'يتم التنفيذ',
        'purchased' => 'تم الشراء',
        'cancelled' => 'ملغي',
        'rejected' => 'مرفوض',
        'postponed' => 'مؤجّل',
        'sufficed' => 'تم الاكتفاء',
    ];

    public const STATUS_COLOR = [
        'new' => 'secondary',
        'pricing' => 'info',
        'finance_review' => 'warning',
        'po_issued' => 'primary',
        'executing' => 'primary',
        'purchased' => 'success',
        'cancelled' => 'dark',
        'rejected' => 'danger',
        'postponed' => 'secondary',
        'sufficed' => 'success',
    ];

    public const PRIORITY_AR = [
        'normal' => 'عادي',
        'urgent' => 'عاجل',
        'monthly' => 'مستلزمات شهرية',
    ];

    /** Statuses that are still "open" (in the pipeline). */
    public const OPEN_STATUSES = ['new', 'pricing', 'finance_review', 'po_issued', 'executing'];

    /** Statuses that mean "closed/terminal". */
    public const CLOSED_STATUSES = ['purchased', 'cancelled', 'rejected', 'sufficed'];

    protected $fillable = [
        'reference', 'ext_number',
        'requester_id', 'requester_name', 'requesting_dept_id', 'requesting_dept_name', 'branch_id',
        'item_name', 'unit', 'quantity', 'category', 'priority',
        'status', 'buyer_id', 'buyer_name',
        'unit_price', 'execution_price', 'selected_vendor_id', 'selected_quotation_id',
        'requested_at', 'pricing_started_at', 'pricing_ended_at',
        'finance_approved_at', 'finance_approved_by',
        'execution_started_at', 'execution_ended_at', 'received_at', 'received_qty',
        'sla_pricing_days', 'sla_execution_days', 'notes', 'extra_notes',
    ];

    protected $casts = [
        'quantity' => 'float',
        'unit_price' => 'float',
        'execution_price' => 'float',
        'requested_at' => 'datetime',
        'pricing_started_at' => 'datetime',
        'pricing_ended_at' => 'datetime',
        'finance_approved_at' => 'datetime',
        'execution_started_at' => 'datetime',
        'execution_ended_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    // -------- relations --------
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function requestingDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'requesting_dept_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function financeApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finance_approved_by');
    }

    public function selectedVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'selected_vendor_id');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(PurchaseQuotation::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /** Combined POs this request was batched into. */
    public function batchOrders(): BelongsToMany
    {
        return $this->belongsToMany(PurchaseOrder::class, 'purchase_order_request');
    }

    /** Display string of every PO number linked to this request (single + batched). */
    public function poNumbers(): string
    {
        $nums = $this->relationLoaded('batchOrders') ? $this->batchOrders->pluck('po_number') : collect();
        if ($this->relationLoaded('orders')) {
            $nums = $nums->merge($this->orders->pluck('po_number'));
        }

        return $nums->filter()->unique()->implode('، ');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(PurchaseRequestUpdate::class)->latest();
    }

    // -------- helpers --------
    public function statusAr(): string
    {
        return self::STATUS_AR[$this->status] ?? $this->status;
    }

    public function statusColor(): string
    {
        return self::STATUS_COLOR[$this->status] ?? 'secondary';
    }

    public function priorityAr(): string
    {
        return self::PRIORITY_AR[$this->priority] ?? $this->priority;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** Pricing duration in days (تاريخ بدء التسعير → انتهائه). */
    public function pricingDays(): ?int
    {
        if (! $this->pricing_started_at || ! $this->pricing_ended_at) {
            return null;
        }

        return (int) $this->pricing_started_at->diffInDays($this->pricing_ended_at);
    }

    /** Execution duration in days (بدء التنفيذ → الانتهاء). */
    public function executionDays(): ?int
    {
        if (! $this->execution_started_at || ! $this->execution_ended_at) {
            return null;
        }

        return (int) $this->execution_started_at->diffInDays($this->execution_ended_at);
    }

    /** Total days from request to finish/now (فرق أيام من الطلب). */
    public function daysSinceRequest(): ?int
    {
        if (! $this->requested_at) {
            return null;
        }
        $end = $this->execution_ended_at ?? $this->received_at
            ?? (in_array($this->status, self::CLOSED_STATUSES, true) ? $this->updated_at : now());

        return (int) $this->requested_at->diffInDays($end);
    }

    /** Delay alert: still open and total days exceed the combined SLA target. */
    public function isDelayed(): bool
    {
        if (! $this->isOpen() || ! $this->requested_at) {
            return false;
        }
        $target = ($this->sla_pricing_days ?? 3) + ($this->sla_execution_days ?? 7);

        return $this->requested_at->diffInDays(now()) > $target;
    }

    public static function nextReference(): string
    {
        $last = static::where('reference', 'like', 'PR-%')->orderByDesc('id')->value('reference');
        $n = $last ? (int) preg_replace('/\D/', '', $last) : 0;

        return 'PR-'.str_pad($n + 1, 5, '0', STR_PAD_LEFT);
    }
}
