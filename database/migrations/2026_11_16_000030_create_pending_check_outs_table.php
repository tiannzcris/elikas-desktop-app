<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whole-family check-outs made on this device, waiting to be sent
 * (POST families/bulk-check-out). A family added on this device and not yet
 * sent is kept by its local id and waits for its own record to go first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_check_outs', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_ref')->unique();
            $table->foreignId('evacuation_center_id')->constrained();
            $table->foreignId('evacuation_event_id')->constrained();
            $table->unsignedBigInteger('family_remote_id')->nullable();
            $table->foreignId('household_family_local_id')->nullable()->constrained('families')->nullOnDelete();
            $table->string('family_label', 200);
            $table->string('status', 20);
            $table->unsignedInteger('people_checked_out')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->string('sync_state', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_check_outs');
    }
};
