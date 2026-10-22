<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tickets', 'source')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->string('source', 20)->nullable();
            });
        }

        if (! Schema::hasColumn('tickets', 'observation_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->foreignId('observation_id')->nullable()->constrained('observations')->nullOnDelete();
            });
        }

        // Backfill legacy rows. Safe to re-run: only tickets with no source yet are touched.
        DB::table('tickets')->whereNull('source')->whereNotNull('visit_id')->update(['source' => 'checklist']);
        DB::table('tickets')->whereNull('source')->where('group_code', 'like', 'MR-%')->update(['source' => 'maintenance']);
    }

    public function down(): void
    {
        // Never drop columns on the live tickets table: it holds production data (backfilled sources
        // and observation links). Rolling back only releases the foreign key so the observations
        // tables from migration 000003 can be dropped; both columns stay in place.
        if (Schema::hasColumn('tickets', 'observation_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropForeign(['observation_id']);
            });
        }
    }
};
