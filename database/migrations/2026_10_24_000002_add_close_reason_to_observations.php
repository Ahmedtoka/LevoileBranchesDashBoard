<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('observations')) {
            return;
        }

        if (! Schema::hasColumn('observations', 'close_reason')) {
            Schema::table('observations', function (Blueprint $table) {
                $table->text('close_reason')->nullable();
            });
        }

        if (! Schema::hasColumn('observations', 'closed_by')) {
            Schema::table('observations', function (Blueprint $table) {
                $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('observations', 'closed_at')) {
            Schema::table('observations', function (Blueprint $table) {
                $table->timestamp('closed_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally a no-op: never drop columns on a table that may hold production data.
        // The nullable close_reason / closed_by / closed_at columns are harmless to leave in place, and the
        // observations table itself is dropped by migration 2026_10_22_000003 when rolling that far back.
    }
};
