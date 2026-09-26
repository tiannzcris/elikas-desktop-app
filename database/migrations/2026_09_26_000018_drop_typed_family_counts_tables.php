<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Typed sectoral/4Ps figures are retired: the central server now counts
    // every sectoral group live (Child/Single-Headed Family from household
    // answers) and discards any typed figure it's sent. Any draft still in
    // these tables could never have reached the server, so nothing that
    // mattered is lost by dropping them.
    public function up(): void
    {
        Schema::dropIfExists('evacuation_center_quick_count_sectoral_groups');
        Schema::dropIfExists('evacuation_center_quick_counts');
    }

    public function down(): void
    {
        Schema::create('evacuation_center_quick_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evacuation_center_id')->constrained();
            $table->foreignId('evacuation_event_id')->constrained();
            $table->unsignedInteger('beneficiaries_4ps')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamps();

            $table->unique(['evacuation_center_id', 'evacuation_event_id']);
        });

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
};
