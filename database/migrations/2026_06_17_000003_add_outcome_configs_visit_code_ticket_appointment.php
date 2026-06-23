<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-outcome field config: what shows on Pass vs Fail, each with `required`.
        Schema::table('checklist_questions', function (Blueprint $table) {
            $table->json('pass_config')->nullable()->after('answer_types');
            $table->json('fail_config')->nullable()->after('pass_config');
        });

        // Human-friendly visit code for linking tickets / repeated-problem analysis.
        Schema::table('visits', function (Blueprint $table) {
            $table->string('code')->nullable()->unique()->after('id');
        });

        // Appointment date/time the assigned employee will go.
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('assigned_at');
        });
    }

    public function down(): void
    {
        Schema::table('checklist_questions', function (Blueprint $table) {
            $table->dropColumn(['pass_config', 'fail_config']);
        });
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('code');
        });
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('scheduled_at');
        });
    }
};
