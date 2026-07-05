<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseQuotation;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestUpdate;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Purchase request lifecycle:
 * new → pricing → finance_review → po_issued → executing → purchased
 * (+ cancelled / rejected / postponed / sufficed at any point).
 */
class PurchaseService
{
    public function log(PurchaseRequest $pr, string $action, ?string $from, ?string $to, ?string $note, ?User $user = null): void
    {
        PurchaseRequestUpdate::create([
            'purchase_request_id' => $pr->id,
            'user_id' => $user?->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
        ]);
    }

    /** Purchasing assigns a buyer and starts pricing. */
    public function assignBuyer(PurchaseRequest $pr, User $buyer, User $actor): void
    {
        $from = $pr->status;
        $pr->update([
            'buyer_id' => $buyer->id,
            'buyer_name' => $buyer->name,
            'status' => 'pricing',
            'pricing_started_at' => $pr->pricing_started_at ?? now(),
        ]);
        $this->log($pr, 'pricing', $from, 'pricing', 'تعيين القائم بالشراء: '.$buyer->name, $actor);
    }

    public function addQuotation(PurchaseRequest $pr, array $data, User $actor): PurchaseQuotation
    {
        $q = $pr->quotations()->create([
            'vendor_id' => $data['vendor_id'] ?? null,
            'vendor_name' => $data['vendor_name'] ?? null,
            'unit_price' => $data['unit_price'] ?? null,
            'total_price' => $data['total_price'] ?? null,
            'supply_duration' => $data['supply_duration'] ?? null,
            'payment_terms' => $data['payment_terms'] ?? null,
            'notes' => $data['notes'] ?? null,
            'quoted_at' => now(),
        ]);
        if ($pr->status === 'new') {
            $pr->update(['status' => 'pricing', 'pricing_started_at' => $pr->pricing_started_at ?? now()]);
        }
        $this->log($pr, 'quote', null, null, 'إضافة عرض سعر — '.$q->vendorLabel(), $actor);

        return $q;
    }

    /** Buyer selects the winning quote → sends to finance for approval. */
    public function selectQuotation(PurchaseRequest $pr, PurchaseQuotation $q, User $actor): void
    {
        $pr->quotations()->update(['is_selected' => false]);
        $q->update(['is_selected' => true]);

        $from = $pr->status;
        $pr->update([
            'selected_quotation_id' => $q->id,
            'selected_vendor_id' => $q->vendor_id,
            'unit_price' => $q->unit_price,
            'execution_price' => $q->total_price,
            'pricing_ended_at' => now(),
            'status' => 'finance_review',
        ]);
        $this->log($pr, 'finance', $from, 'finance_review', 'اعتماد عرض '.$q->vendorLabel().' وإرساله للمالية', $actor);
    }

    public function financeApprove(PurchaseRequest $pr, User $actor, ?string $note = null): void
    {
        $from = $pr->status;
        $pr->update([
            'finance_approved_at' => now(),
            'finance_approved_by' => $actor->id,
            'status' => 'po_issued',
        ]);
        $this->log($pr, 'finance_approved', $from, 'po_issued', $note ?: 'موافقة المالية على العرض', $actor);
    }

    public function financeReject(PurchaseRequest $pr, User $actor, ?string $note = null): void
    {
        $from = $pr->status;
        $pr->update(['status' => 'pricing', 'pricing_ended_at' => null]);
        $this->log($pr, 'finance_rejected', $from, 'pricing', $note ?: 'رفض المالية — إعادة التسعير', $actor);
    }

    public function issuePo(PurchaseRequest $pr, array $data, User $actor): PurchaseOrder
    {
        $po = $pr->orders()->create([
            'po_number' => $data['po_number'] ?? PurchaseOrder::nextNumber(),
            'ext_request_number' => $pr->ext_number,
            'vendor_id' => $data['vendor_id'] ?? $pr->selected_vendor_id,
            'vendor_name' => $data['vendor_name'] ?? optional($pr->selectedVendor)->name,
            'issued_at' => now(),
            'amount' => $data['amount'] ?? $pr->execution_price,
            'expected_delivery_at' => $data['expected_delivery_at'] ?? null,
            'payment_status' => $data['payment_status'] ?? null,
            'payment_terms' => $data['payment_terms'] ?? null,
            'supply_status' => 'none',
        ]);
        if ($pr->status !== 'po_issued') {
            $pr->update(['status' => 'po_issued']);
        }
        $this->log($pr, 'po', null, 'po_issued', 'إصدار أمر توريد رقم '.$po->po_number, $actor);

        return $po;
    }

    public function startExecution(PurchaseRequest $pr, User $actor): void
    {
        $from = $pr->status;
        $pr->update(['status' => 'executing', 'execution_started_at' => $pr->execution_started_at ?? now()]);
        $this->log($pr, 'execute', $from, 'executing', 'بدء التنفيذ', $actor);
    }

    public function markReceived(PurchaseRequest $pr, array $data, User $actor): void
    {
        $from = $pr->status;
        $pr->update([
            'status' => 'purchased',
            'execution_ended_at' => now(),
            'received_at' => $data['received_at'] ?? now(),
            'received_qty' => $data['received_qty'] ?? null,
        ]);
        // Reflect supply status on the latest PO if present.
        if ($po = $pr->orders()->latest()->first()) {
            $po->update(['supply_status' => $data['supply_status'] ?? 'full']);
        }
        $this->log($pr, 'receive', $from, 'purchased', 'تم الاستلام والشراء', $actor);
    }

    /** Terminal transitions: cancelled / rejected / postponed / sufficed. */
    public function setStatus(PurchaseRequest $pr, string $status, User $actor, ?string $note = null): void
    {
        $from = $pr->status;
        $pr->update(['status' => $status]);
        $this->log($pr, 'status_change', $from, $status, $note, $actor);
    }
}
