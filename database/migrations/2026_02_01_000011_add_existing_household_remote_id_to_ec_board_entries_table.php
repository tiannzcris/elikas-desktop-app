<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Covers a real gap: "existing household" previously only ever meant a
    // household already present in THIS device's local families cache
    // (household_family_local_id). Once the household picker also lists
    // households live-fetched from the central server (see
    // EvacuationCenterController::refreshHouseholds()), a chosen household
    // may have no local Family row at all -- there's nothing to point
    // household_family_local_id at. This column carries the central
    // server's own family id directly for that case, bypassing the local
    // row entirely; EcBoardEntry::toSyncPayload() checks it first.
    public function up(): void
    {
        Schema::table('ec_board_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('existing_household_remote_id')->nullable()->after('household_family_local_id');
        });
    }

    public function down(): void
    {
        Schema::table('ec_board_entries', function (Blueprint $table) {
            $table->dropColumn('existing_household_remote_id');
        });
    }
};
