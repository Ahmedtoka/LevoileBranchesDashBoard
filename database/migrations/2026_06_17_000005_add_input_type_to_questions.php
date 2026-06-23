<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_questions', function (Blueprint $table) {
            $table->string('input_type')->default('boolean')->after('type'); // boolean | options
            $table->string('options_style')->default('buttons')->after('options'); // buttons | dropdown
        });
    }

    public function down(): void
    {
        Schema::table('checklist_questions', function (Blueprint $table) {
            $table->dropColumn(['input_type', 'options_style']);
        });
    }
};
