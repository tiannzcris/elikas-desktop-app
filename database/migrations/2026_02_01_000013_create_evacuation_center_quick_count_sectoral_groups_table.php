<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Mirrors the backend's own evacuation_center_quick_count_sectoral_groups
    // table exactly (rows-not-flat-columns, one row per group) -- see
    // EvacuationCenterQuickCount::SECTORAL_GROUPS. Kept a separate table
    // from evacuation_center_quick_counts for the same reason the backend
    // does: sectoral categories are an unrelated, fixed list from age
    // brackets and never queried together with them.
    public function up(): void
    {
        Schema::create('evacuation_center_quick_count_sectoral_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evacuation_center_quick_count_id')->constrained()->cascadeOnDelete();
            $table->string('sectoral_group', 30);
            $table->unsignedInteger('male_count')->default(0);
            $table->unsignedInteger('female_count')->default(0);
            $table->timestamps();

            $table->unique(['evacuation_center_quick_count_id', 'sectoral_group'], 'ec_qc_sectoral_group_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evacuation_center_quick_count_sectoral_groups');
    }
};
