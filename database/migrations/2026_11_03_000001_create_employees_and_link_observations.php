<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branch staff as their own records (owner request 2026-11-03, CCTV first): the weekly sheets carried a
 * free-text first name only, so the per-employee pivot split one person over several spellings.
 * observations.employee_id points at the record; employee_name stays as the display copy for old rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table) {
                $table->id();
                $table->string('code', 50)->nullable()->unique();
                $table->string('name', 191);
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('job_title', 100)->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->index(['branch_id', 'name']);
            });
        }

        if (! Schema::hasColumn('observations', 'employee_id')) {
            Schema::table('observations', function (Blueprint $table) {
                $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('observations', 'employee_id')) {
            Schema::table('observations', function (Blueprint $table) {
                $table->dropConstrainedForeignId('employee_id');
            });
        }
        Schema::dropIfExists('employees');
    }
};
