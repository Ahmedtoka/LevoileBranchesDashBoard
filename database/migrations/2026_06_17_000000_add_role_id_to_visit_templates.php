<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_templates', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('type')
                ->constrained('roles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('visit_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });
    }
};
