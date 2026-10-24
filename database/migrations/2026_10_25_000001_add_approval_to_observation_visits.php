<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan H: the manager approval gate. A submitted visit now waits for the reporting department's manager;
 * tickets are created at approval. Visits submitted under the old flow (tickets created at submit) are
 * finished work, so the one-time backfill marks every `submitted` visit with a `submitted_at` as `approved`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('observation_visits') || Schema::hasColumn('observation_visits', 'approved_at')) {
            return;
        }

        Schema::table('observation_visits', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });

        DB::table('observation_visits')
            ->where('status', 'submitted')
            ->whereNotNull('submitted_at')
            ->update([
                'status' => 'approved',
                'approved_by' => DB::raw('user_id'),
                'approved_at' => DB::raw('submitted_at'),
            ]);
    }

    public function down(): void
    {
        // Intentionally a no-op: never drop columns on a table that may hold production data, and the
        // status backfill is not reversible (the old flow had no separate approval step).
    }
};
