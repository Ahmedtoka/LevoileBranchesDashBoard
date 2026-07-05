<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Many purchase requests can share a single (combined) purchase order —
 * e.g. batching all "office supplies" requests into one PO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_request', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('purchase_request_id')->constrained('purchase_requests')->cascadeOnDelete();
            $table->primary(['purchase_order_id', 'purchase_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_request');
    }
};
