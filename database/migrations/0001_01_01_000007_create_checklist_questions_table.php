<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_section_id')->constrained('checklist_sections')->cascadeOnDelete();
            $table->text('question_text');
            $table->text('question_text_ar')->nullable();

            // boolean, text, number, percentage, options, table, photo
            $table->string('type')->default('boolean');
            $table->json('options')->nullable();        // for "options" / table columns
            $table->unsignedInteger('max_score')->nullable(); // scored templates

            // ticket routing
            $table->foreignId('responsible_department_id')->nullable()
                ->constrained('departments')->nullOnDelete();
            $table->boolean('comment_required_on_fail')->default(true);
            $table->boolean('photo_required_on_fail')->default(true);
            $table->boolean('auto_create_ticket_on_fail')->default(true);
            $table->boolean('is_people_issue')->default(false); // staff/people -> employee multiselect
            $table->string('default_priority')->default('medium'); // low/medium/high/critical
            $table->unsignedInteger('sla_hours')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_questions');
    }
};
