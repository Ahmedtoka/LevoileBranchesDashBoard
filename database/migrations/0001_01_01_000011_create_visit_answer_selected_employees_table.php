<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_answer_selected_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_answer_id')->constrained('visit_answers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['visit_answer_id', 'user_id'], 'va_emp_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_answer_selected_employees');
    }
};
