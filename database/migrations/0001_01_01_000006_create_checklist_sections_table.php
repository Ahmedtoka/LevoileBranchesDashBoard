<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_template_id')->constrained('visit_templates')->cascadeOnDelete();
            $table->string('title');
            $table->string('title_ar')->nullable();
            $table->unsignedInteger('weight')->nullable(); // for scored templates
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_sections');
    }
};
