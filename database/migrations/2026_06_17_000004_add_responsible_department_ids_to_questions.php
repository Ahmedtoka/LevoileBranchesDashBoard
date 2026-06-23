<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checklist_questions', function (Blueprint $table) {
            $table->json('responsible_department_ids')->nullable()->after('responsible_department_id');
        });
    }

    public function down(): void
    {
        Schema::table('checklist_questions', function (Blueprint $table) {
            $table->dropColumn('responsible_department_ids');
        });
    }
};
