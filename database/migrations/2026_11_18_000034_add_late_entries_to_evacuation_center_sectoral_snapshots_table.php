<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Late entries at a center (GET .../quick-count late_entries): families
 * registered there after their barangay's latest final count submission,
 * not yet in a validated count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evacuation_center_sectoral_snapshots', function (Blueprint $table) {
            $table->unsignedInteger('late_entries')->nullable()->after('persons_now');
        });
    }

    public function down(): void
    {
        Schema::table('evacuation_center_sectoral_snapshots', function (Blueprint $table) {
            $table->dropColumn('late_entries');
        });
    }
};
