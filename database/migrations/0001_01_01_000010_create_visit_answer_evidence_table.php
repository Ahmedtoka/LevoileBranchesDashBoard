<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_answer_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_answer_id')->constrained('visit_answers')->cascadeOnDelete();
            $table->string('path');                 // storage path or url
            $table->string('kind')->default('photo'); // photo / before / after
            $table->string('source')->default('camera'); // camera / gallery
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_answer_evidence');
    }
};
