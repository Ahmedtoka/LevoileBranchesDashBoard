<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The HR staff export (owner request 2026-11-03): every employee of the company with their code, direct
 * manager, section, department, sector and place of work. Branch staff also get branch_id; head office and
 * warehouse staff keep only the place of work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('manager_code', 50)->nullable()->after('job_title');
            $table->string('manager_name', 191)->nullable()->after('manager_code');
            $table->string('section', 100)->nullable()->after('manager_name');
            $table->string('department', 100)->nullable()->after('section');
            $table->string('sector', 100)->nullable()->after('department');
            $table->string('work_location', 100)->nullable()->after('sector');
            $table->string('phone', 30)->nullable()->after('work_location');
            $table->string('email', 191)->nullable()->after('phone');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['name']);
            $table->dropColumn(['manager_code', 'manager_name', 'section', 'department', 'sector', 'work_location', 'phone', 'email']);
        });
    }
};
