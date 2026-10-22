<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('observation_visits')) {
            Schema::create('observation_visits', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('type', 20)->default('field');
                $table->foreignId('department_id')->constrained('departments');
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('user_id')->constrained('users');
                $table->date('visit_date');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->decimal('checkin_latitude', 10, 7)->nullable();
                $table->decimal('checkin_longitude', 10, 7)->nullable();
                $table->boolean('checkin_simulated')->default(false);
                $table->string('status', 20)->default('draft');
                $table->text('general_notes')->nullable();
                $table->string('source', 20);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('observations')) {
            Schema::create('observations', function (Blueprint $table) {
                $table->id();
                $table->string('reference')->unique();
                $table->foreignId('observation_visit_id')->constrained('observation_visits')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('reporting_department_id')->constrained('departments');
                $table->foreignId('responsible_department_id')->nullable()->constrained('departments')->nullOnDelete();
                $table->json('cc_department_ids')->nullable();
                $table->foreignId('category_id')->nullable()->constrained('observation_categories')->nullOnDelete();
                $table->foreignId('catalog_item_id')->nullable()->constrained('observation_catalog_items')->nullOnDelete();
                $table->string('kind', 20);
                $table->foreignId('risk_level_id')->nullable()->constrained('risk_levels')->nullOnDelete();
                $table->text('risk_override_reason')->nullable();
                $table->string('title');
                $table->text('description');
                $table->string('product_or_area')->nullable();
                $table->json('extra_values')->nullable();
                $table->string('rating', 20)->nullable();
                $table->date('event_date');
                $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
                $table->string('status', 30)->default('open');
                $table->string('classification_status', 30)->default('manual');
                $table->foreignId('classification_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('classification_reviewed_at')->nullable();
                $table->unsignedInteger('repeat_count')->default(0);
                $table->foreignId('escalated_from_risk_level_id')->nullable()->constrained('risk_levels')->nullOnDelete();
                $table->foreignId('created_by')->constrained('users');
                $table->string('import_source')->nullable();
                $table->string('import_row')->nullable();
                $table->timestamps();

                $table->index(['branch_id', 'status']);
                $table->index(['reporting_department_id', 'created_at']);
                $table->index(['responsible_department_id', 'status']);
                $table->index(['catalog_item_id', 'branch_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('observation_photos')) {
            Schema::create('observation_photos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('observation_id')->constrained('observations')->cascadeOnDelete();
                $table->string('path');
                $table->string('kind', 20)->default('before');
                $table->string('source', 20)->default('camera');
                $table->foreignId('uploaded_by')->constrained('users');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('observation_verifications')) {
            Schema::create('observation_verifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('observation_id')->constrained('observations')->cascadeOnDelete();
                $table->foreignId('verified_by')->constrained('users');
                $table->foreignId('observation_visit_id')->nullable()->constrained('observation_visits')->nullOnDelete();
                $table->string('result', 20);
                $table->text('notes')->nullable();
                $table->timestamp('verified_at');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_classifications')) {
            Schema::create('ai_classifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('observation_visit_id')->nullable()->constrained('observation_visits')->nullOnDelete();
                $table->foreignId('observation_id')->nullable()->constrained('observations')->nullOnDelete();
                $table->string('request_hash');
                $table->text('input_text');
                $table->json('suggested')->nullable();
                $table->json('employee_final')->nullable();
                $table->json('admin_final')->nullable();
                $table->string('model')->nullable();
                $table->unsignedInteger('input_tokens')->nullable();
                $table->unsignedInteger('output_tokens')->nullable();
                $table->unsignedInteger('latency_ms')->nullable();
                $table->string('status', 20)->default('ok');
                $table->text('error')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Only the five brand-new tables are dropped, children first; no existing table or column is touched.
        Schema::dropIfExists('ai_classifications');
        Schema::dropIfExists('observation_verifications');
        Schema::dropIfExists('observation_photos');
        Schema::dropIfExists('observations');
        Schema::dropIfExists('observation_visits');
    }
};
