<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** is_demo: a demonstration event with made-up data ("DEMO - ..."), marked wherever shown. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evacuation_events', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('status');
            $table->date('start_date')->nullable()->after('is_demo');
        });
    }

    public function down(): void
    {
        Schema::table('evacuation_events', function (Blueprint $table) {
            $table->dropColumn(['is_demo', 'start_date']);
        });
    }
};
