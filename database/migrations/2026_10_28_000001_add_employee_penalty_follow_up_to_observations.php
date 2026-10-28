<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three optional fields the legacy weekly sheets carried and the module lacked (owner request 2026-10-28):
 * the employee the point is about (CCTV "الموظف" column, now first-class so the employee report can group on it),
 * the penalty decided ("الجزاء") and the follow-up date ("تاريخ المتابعة" of the stock-control sheet).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('observations')) {
            return;
        }

        if (! Schema::hasColumn('observations', 'employee_name')) {
            Schema::table('observations', function (Blueprint $table) {
                $table->string('employee_name', 191)->nullable()->index();
            });

            // Points recorded before this column kept the employee in extra_values.employee (CCTV late-opening item).
            try {
                DB::table('observations')
                    ->whereNotNull('extra_values')
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(extra_values, '$.employee')) IS NOT NULL")
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(extra_values, '$.employee')) <> ''")
                    ->update(['employee_name' => DB::raw("JSON_UNQUOTE(JSON_EXTRACT(extra_values, '$.employee'))")]);
            } catch (\Throwable) {
                // Older engines without JSON functions: the backfill is cosmetic; new rows are written directly.
            }
        }

        if (! Schema::hasColumn('observations', 'penalty')) {
            Schema::table('observations', function (Blueprint $table) {
                $table->string('penalty', 500)->nullable();
            });
        }

        if (! Schema::hasColumn('observations', 'follow_up_at')) {
            Schema::table('observations', function (Blueprint $table) {
                $table->date('follow_up_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally a no-op: never drop columns on a table that may hold production data.
    }
};
