<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The actual data this offline companion exists to collect. Mirrors
    // the central server's families table closely enough that syncing is
    // a straightforward field-by-field POST to the same
    // /api/v1/families/register endpoint the web app already uses --
    // see SyncService in the next step.
    public function up(): void
    {
        Schema::create('families', function (Blueprint $table) {
            $table->id();
            $table->foreignId('barangay_id')->constrained(); // local barangays cache row
            $table->foreignId('evacuation_event_id')->constrained(); // local evacuation_events cache row
            $table->foreignId('evacuation_center_id')->nullable()->constrained();
            $table->enum('displacement_type', ['inside_center', 'outside_center']);
            $table->boolean('is_4ps_beneficiary')->default(false);

            // --- Sync tracking -- present on every table this device
            // creates data in, absent from the read-only cache tables above ---
            $table->unsignedBigInteger('remote_id')->nullable()->comment('ID assigned by the central server once synced');
            $table->timestamp('synced_at')->nullable()->comment('Null = not yet synced to the central server');
            $table->text('sync_error')->nullable()->comment('Last sync failure reason, if any, shown to the user');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('families');
    }
};
