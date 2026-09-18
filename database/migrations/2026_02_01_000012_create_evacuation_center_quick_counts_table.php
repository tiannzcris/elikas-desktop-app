<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The EC Board's manually-reported sectoral/4Ps figures -- confirmed
    // missing from this app entirely until now (see
    // EvacuationCenterQuickCount on the backend: sectoral flags aren't
    // known at "Add Evacuee" time, so unlike age/sex this can never be
    // derived from individual EcBoardEntry rows and stays a directly-typed
    // aggregate). One row per (center, event), overwritten in place as
    // staff resave it -- same synced_at/sync_error pending-state machine
    // as families/ec_board_entries, but a single upserted "simple value
    // update" per center+event rather than a growing queue of individual
    // records, since there is only ever one current figure to report, not
    // one per evacuee.
    public function up(): void
    {
        Schema::create('evacuation_center_quick_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evacuation_center_id')->constrained();
            $table->foreignId('evacuation_event_id')->constrained();
            $table->unsignedInteger('beneficiaries_4ps')->default(0);
            $table->timestamp('synced_at')->nullable()->comment('Null = not yet synced to the central server');
            $table->text('sync_error')->nullable()->comment('Last sync failure reason, if any, shown to the user');
            $table->timestamps();

            $table->unique(['evacuation_center_id', 'evacuation_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evacuation_center_quick_counts');
    }
};
