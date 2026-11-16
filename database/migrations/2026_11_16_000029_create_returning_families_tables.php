<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Returning families (server step 10), offline:
 *  - returning_family_snapshots: per current event, the past events and
 *    their re-enrollable families of the official's barangay, with members,
 *    as the server last listed them (wiped at logout);
 *  - pending_re_enrollments: families ticked on this device, waiting to be
 *    sent (POST .../re-enroll, with client_ref).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('returning_family_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evacuation_event_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('past_events');
            $table->json('families_by_event');
            $table->timestamp('fetched_at');
            $table->timestamps();
        });

        Schema::create('pending_re_enrollments', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_ref')->unique();
            $table->foreignId('evacuation_center_id')->constrained();
            $table->foreignId('evacuation_event_id')->constrained();
            $table->unsignedBigInteger('source_family_remote_id');
            $table->string('family_name', 200);
            $table->string('source_event_name', 200)->nullable();
            $table->json('members');
            $table->string('duplicate_decision', 20)->nullable();
            $table->unsignedBigInteger('link_family_remote_id')->nullable();
            $table->json('possible_duplicates')->nullable();
            $table->unsignedBigInteger('remote_family_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->string('sync_state', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_re_enrollments');
        Schema::dropIfExists('returning_family_snapshots');
    }
};
