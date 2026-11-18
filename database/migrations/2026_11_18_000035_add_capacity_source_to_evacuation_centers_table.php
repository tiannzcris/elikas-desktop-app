<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a center's capacity comes from (server, Nov 2026): official,
 * estimated (from the 2025 peak or the median of its type) or unknown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evacuation_centers', function (Blueprint $table) {
            $table->string('capacity_source', 20)->nullable()->after('capacity_persons');
            $table->string('capacity_basis', 20)->nullable()->after('capacity_source');
        });
    }

    public function down(): void
    {
        Schema::table('evacuation_centers', function (Blueprint $table) {
            $table->dropColumn(['capacity_source', 'capacity_basis']);
        });
    }
};
