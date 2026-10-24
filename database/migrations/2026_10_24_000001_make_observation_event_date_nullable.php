<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Spec section 13: an imported row whose raw date falls outside its week has no confirmed event date
     * (null). Live entry keeps setting the visit date, so nothing else changes.
     */
    public function up(): void
    {
        if (Schema::hasTable('observations') && Schema::hasColumn('observations', 'event_date')) {
            Schema::table('observations', function (Blueprint $table) {
                $table->date('event_date')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Intentionally a no-op: tightening the column back to NOT NULL would fail on imported rows.
    }
};
