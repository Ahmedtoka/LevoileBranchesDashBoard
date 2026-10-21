<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('risk_levels')) {
            Schema::create('risk_levels', function (Blueprint $table) {
                $table->id();
                $table->string('slug')->unique();
                $table->string('name_ar');
                $table->string('name_en');
                $table->string('color', 20);
                $table->unsignedInteger('sort_order');
                $table->unsignedInteger('default_sla_hours')->nullable();
                $table->boolean('requires_field_verification')->default(false);
                $table->string('ticket_priority', 20)->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('areas')) {
            Schema::create('areas', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('name_ar')->nullable();
                $table->string('slug')->unique();
                $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('branches', 'area_id')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('departments', 'is_reporting')) {
            Schema::table('departments', function (Blueprint $table) {
                $table->boolean('is_reporting')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('departments', 'is_reporting')) {
            Schema::table('departments', function (Blueprint $table) {
                $table->dropColumn('is_reporting');
            });
        }

        if (Schema::hasColumn('branches', 'area_id')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->dropConstrainedForeignId('area_id');
            });
        }

        Schema::dropIfExists('areas');
        Schema::dropIfExists('risk_levels');
    }
};
