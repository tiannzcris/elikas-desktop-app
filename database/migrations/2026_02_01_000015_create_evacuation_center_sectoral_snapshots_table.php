<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Read-only local cache of the central server's own LIVE sectoral/4Ps
     * figures for one center+event -- the sectoral equivalent of
     * evacuation_center_breakdowns (age/sex), which this exactly mirrors:
     * refreshed on demand via the same fetchCenterQuickCount() call
     * refreshBreakdown() already makes (see EvacuationCenterController::
     * refreshSectoralLastKnown()), never written to by anything the user
     * enters on this device. This is what makes the sectoral/4Ps display
     * a real "Last known" vs "Pending" dual view, matching the age/sex
     * breakdown's own established pattern (and the mobile app's own
     * now-proven version of this exact feature) -- before this table
     * existed, EvacuationCenterQuickCount did double duty as BOTH "the
     * local pending edit" AND, once synced, an implied "current state",
     * so an edit in progress had nowhere to preserve the previous
     * synced snapshot to compare against.
     *
     * sectoral_groups is stored as JSON (all 8 groups, zero-filled, same
     * shape as the real API response) rather than a child table --
     * purely a display cache with no need to query into individual
     * groups the way EvacuationCenterQuickCountSectoralGroup (the
     * PENDING side's own child table) does.
     */
    public function up(): void
    {
        Schema::create('evacuation_center_sectoral_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evacuation_center_id')->constrained();
            $table->foreignId('evacuation_event_id')->constrained();
            $table->unsignedInteger('beneficiaries_4ps')->default(0);
            $table->json('sectoral_groups');
            $table->string('updated_by_name')->nullable();
            $table->timestamp('server_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['evacuation_center_id', 'evacuation_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evacuation_center_sectoral_snapshots');
    }
};
