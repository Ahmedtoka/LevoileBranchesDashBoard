<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('observations') && ! Schema::hasColumn('observations', 'last_overdue_notified_at')) {
            Schema::table('observations', function (Blueprint $table) {
                $table->timestamp('last_overdue_notified_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally a no-op: never drop columns on a table that may hold production data.
        // The nullable last_overdue_notified_at column is harmless to leave in place, and the
        // observations table itself is dropped by migration 000003 when rolling that far back.
    }
};
