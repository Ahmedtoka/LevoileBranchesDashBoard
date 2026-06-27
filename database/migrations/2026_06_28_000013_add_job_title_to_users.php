<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'job_title')) {
                $table->string('job_title')->nullable()->after('name');
            }
            if (! Schema::hasColumn('users', 'staff_code')) {
                $table->string('staff_code')->nullable()->after('job_title');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['job_title', 'staff_code']);
        });
    }
};
