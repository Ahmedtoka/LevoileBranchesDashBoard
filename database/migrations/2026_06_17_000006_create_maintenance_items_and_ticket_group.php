<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_items', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('label_ar')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Groups tickets that came from one maintenance request.
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('group_code')->nullable()->index()->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('group_code');
        });
        Schema::dropIfExists('maintenance_items');
    }
};
