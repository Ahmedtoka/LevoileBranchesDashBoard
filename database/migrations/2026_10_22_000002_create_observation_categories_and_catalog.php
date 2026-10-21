<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('observation_categories')) {
            Schema::create('observation_categories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
                $table->string('slug');
                $table->string('name_ar');
                $table->string('name_en');
                $table->string('default_kind', 20)->default('issue');
                $table->foreignId('default_risk_level_id')->nullable()->constrained('risk_levels')->nullOnDelete();
                $table->foreignId('default_responsible_department_id')->nullable()->constrained('departments')->nullOnDelete();
                $table->json('default_cc_department_ids')->nullable();
                $table->unsignedInteger('default_sla_hours')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();

                $table->unique(['department_id', 'slug']);
            });
        }

        if (! Schema::hasTable('observation_catalog_items')) {
            Schema::create('observation_catalog_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('category_id')->constrained('observation_categories')->cascadeOnDelete();
                $table->string('code')->unique();
                $table->string('label_ar');
                $table->string('label_en');
                $table->json('keywords')->nullable();
                $table->string('kind', 20)->default('issue');
                $table->foreignId('risk_level_id')->nullable()->constrained('risk_levels')->nullOnDelete();
                $table->foreignId('responsible_department_id')->nullable()->constrained('departments')->nullOnDelete();
                $table->json('cc_department_ids')->nullable();
                $table->unsignedInteger('sla_hours')->nullable();
                $table->json('extra_fields')->nullable();
                $table->json('escalation_rules')->nullable();
                $table->unsignedInteger('usage_count')->default(0);
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Only the two brand-new tables are dropped; no existing table or column is touched.
        Schema::dropIfExists('observation_catalog_items');
        Schema::dropIfExists('observation_categories');
    }
};
