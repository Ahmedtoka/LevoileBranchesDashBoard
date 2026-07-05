<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchasing module — mirrors the developed purchasing sheet (Requisitions + POs + Vendors)
 * with full timing tracking: request → pricing (multi-quote) → finance approval → PO → execution → receipt.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- Vendors (الموردين) ----
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('specialty')->nullable();       // التخصص
            $table->string('supply_duration')->nullable();  // مدة التوريد (free text)
            $table->unsignedTinyInteger('rating')->nullable(); // 1..5
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // ---- Purchase requests / requisitions (طلبات الشراء) ----
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();     // PR-0001
            $table->string('ext_number')->nullable();  // رقم طلب الشراء الأصلي من الشيت

            // Requester (مقدّم الطلب) — user + raw fallbacks for imported rows
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requester_name')->nullable();
            $table->foreignId('requesting_dept_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('requesting_dept_name')->nullable(); // الإدارة الطالبة (raw)
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // Item
            $table->string('item_name');                 // البيان / اسم الصنف
            $table->string('unit')->nullable();          // الوحدة
            $table->decimal('quantity', 14, 2)->nullable(); // الكمية / العدد
            $table->string('category')->nullable();      // التصنيف
            $table->string('priority')->default('normal'); // normal / urgent / monthly

            // Workflow
            $table->string('status')->default('new');
            $table->foreignId('buyer_id')->nullable()->constrained('users')->nullOnDelete(); // القائم بالشراء
            $table->string('buyer_name')->nullable();

            // Final chosen figures
            $table->decimal('unit_price', 14, 2)->nullable();      // سعر الوحدة
            $table->decimal('execution_price', 14, 2)->nullable(); // سعر التنفيذ (إجمالي)
            $table->foreignId('selected_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->unsignedBigInteger('selected_quotation_id')->nullable();

            // Timing (كل تواريخ السايكل)
            $table->timestamp('requested_at')->nullable();          // تاريخ الطلب
            $table->timestamp('pricing_started_at')->nullable();    // تاريخ بدء التسعير
            $table->timestamp('pricing_ended_at')->nullable();      // تاريخ انتهاء التسعير
            $table->timestamp('finance_approved_at')->nullable();
            $table->foreignId('finance_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('execution_started_at')->nullable();  // تاريخ بدء التنفيذ
            $table->timestamp('execution_ended_at')->nullable();    // تاريخ الانتهاء
            $table->timestamp('received_at')->nullable();           // تاريخ الاستلام
            $table->string('received_qty')->nullable();             // ما تم استلامه (قد يكون نص "356 متر")

            // SLA targets (days) — used for delay alerts
            $table->unsignedSmallInteger('sla_pricing_days')->nullable();
            $table->unsignedSmallInteger('sla_execution_days')->nullable();

            $table->text('notes')->nullable();       // ملاحظات (الطلب)
            $table->text('extra_notes')->nullable(); // ملاحظات أخرى
            $table->timestamps();

            $table->index(['status', 'priority']);
            $table->index('requested_at');
        });

        // ---- Quotations (عروض الأسعار) ----
        Schema::create('purchase_quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained('purchase_requests')->cascadeOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('vendor_name')->nullable();
            $table->decimal('unit_price', 14, 2)->nullable();
            $table->decimal('total_price', 14, 2)->nullable();
            $table->string('supply_duration')->nullable();
            $table->string('payment_terms')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_selected')->default(false);
            $table->timestamp('quoted_at')->nullable();
            $table->timestamps();
        });

        // ---- Purchase orders (أوامر التوريد PO) ----
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number');                 // رقم PO
            $table->foreignId('purchase_request_id')->nullable()->constrained('purchase_requests')->nullOnDelete();
            $table->string('ext_request_number')->nullable(); // رقم طلب الشراء (قد يكون "200 - 201")
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->string('vendor_name')->nullable();
            $table->timestamp('issued_at')->nullable();      // تاريخ الإصدار
            $table->decimal('amount', 14, 2)->nullable();    // قيمة الأمر
            $table->timestamp('expected_delivery_at')->nullable(); // تاريخ التوريد المتوقع
            $table->string('supply_status')->nullable();     // full / partial / over / none
            $table->string('payment_status')->nullable();    // cash / cheque / credit
            $table->string('payment_terms')->nullable();     // شروط السداد
            $table->timestamps();
        });

        // ---- Timeline (سجل الحالة) ----
        Schema::create('purchase_request_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained('purchase_requests')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');                 // created / pricing / quote / finance / po / execute / receive / status_change
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_updates');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('purchase_quotations');
        Schema::dropIfExists('purchase_requests');
        Schema::dropIfExists('vendors');
    }
};
