<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relief boxes given on this computer, waiting to be sent
 * (POST /box-relief/rounds/{round}/handouts with client_ref,
 * docs/backend/apps-api-2026-11-24.md 1.2). One box per family per round.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Only when missing: safe to run again (2.1.1).
        if (Schema::hasTable('pending_handouts')) {
            return;
        }
        Schema::create('pending_handouts', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_ref')->unique();
            $table->foreignId('evacuation_event_id')->constrained();
            $table->unsignedBigInteger('round_remote_id');
            $table->unsignedInteger('round_number')->nullable();
            $table->unsignedBigInteger('family_remote_id');
            $table->text('family_label');
            // Families with a higher priority still without a box when this one was given.
            $table->unsignedInteger('higher_priority_waiting')->default(0);
            $table->unsignedBigInteger('handout_remote_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->string('sync_state', 20)->nullable();
            $table->timestamps();
            $table->unique(['round_remote_id', 'family_remote_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_handouts');
    }
};
