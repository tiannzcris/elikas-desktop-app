<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Read-only local cache, same spirit as barangays/evacuation_events/
    // evacuation_centers: a snapshot of the central server's own LIVE tally
    // of already-synced evacuees per center+event, broken down by age
    // bracket and sex. Unlike those other reference tables, this is NOT
    // refreshed in bulk alongside the rest of reference data -- the real
    // central endpoint (quick-count) is scoped to one center+event per
    // call, so this is refreshed on demand, only for the one center+event
    // a user actually opens (see EvacuationCenterController::show()) --
    // never written to by anything on this device, so unlike families/
    // ec_board_entries there is no synced_at/sync_error pair here, only a
    // snapshot that gets wiped and replaced for that one center+event on
    // every successful refresh.
    public function up(): void
    {
        Schema::create('evacuation_center_breakdowns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evacuation_center_id')->constrained();
            $table->foreignId('evacuation_event_id')->constrained();
            $table->enum('sex', ['male', 'female']);
            $table->string('age_bracket', 20);
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evacuation_center_breakdowns');
    }
};
