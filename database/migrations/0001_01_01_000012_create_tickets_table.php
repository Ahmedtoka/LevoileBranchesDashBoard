<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();   // TK-0001
            $table->string('title');
            $table->text('description')->nullable();

            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->foreignId('visit_answer_id')->nullable()->constrained('visit_answers')->nullOnDelete();
            $table->foreignId('checklist_question_id')->nullable()->constrained('checklist_questions')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            // open, assigned, on_the_way, in_progress, waiting_approval, rejected, closed
            $table->string('status')->default('open');
            $table->string('priority')->default('medium'); // low/medium/high/critical
            $table->unsignedInteger('sla_hours')->nullable();
            $table->timestamp('due_at')->nullable();

            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('resolved_at')->nullable();  // marked fixed/waiting approval
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('reopen_count')->default(0);

            $table->string('category')->nullable(); // lamp, pos, cctv, cleaning, stock...
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
