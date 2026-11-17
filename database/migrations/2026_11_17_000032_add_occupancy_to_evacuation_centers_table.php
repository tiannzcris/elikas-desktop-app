<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The center's capacity and the server's own figures at the last refresh
 * (GET /evacuation-centers?detail=1): people checked in, and the status it
 * shows (people inside make a center active, or full near capacity).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evacuation_centers', function (Blueprint $table) {
            $table->unsignedInteger('capacity_persons')->nullable()->after('status');
            $table->unsignedInteger('current_occupancy')->nullable()->after('capacity_persons');
            $table->string('effective_status', 30)->nullable()->after('current_occupancy');
        });
    }

    public function down(): void
    {
        Schema::table('evacuation_centers', function (Blueprint $table) {
            $table->dropColumn(['capacity_persons', 'current_occupancy', 'effective_status']);
        });
    }
};
