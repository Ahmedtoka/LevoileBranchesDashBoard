<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignId('checklist_question_id')->constrained('checklist_questions')->cascadeOnDelete();

            // pass, fail, na (for boolean) — generic result flag
            $table->string('result')->nullable();
            $table->text('value')->nullable();   // text/number/percentage/options/table(json) answer
            $table->decimal('score', 6, 2)->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['visit_id', 'checklist_question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_answers');
    }
};
