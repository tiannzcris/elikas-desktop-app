<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Families to evacuate (docs/backend/apps-api-2026-11-22.md 3-4):
 *  - the purok's high-risk marks (flood, landslide), cached from GET /puroks;
 *  - check-ins of listed families made on this device, waiting to be sent
 *    (POST /families-to-evacuate/{id}/check-in with client_ref).
 * The list itself is a copy of the server's (server_snapshots).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puroks', function (Blueprint $table) {
            $table->boolean('high_risk_flood')->default(false);
            $table->boolean('high_risk_landslide')->default(false);
        });

        Schema::create('pending_list_check_ins', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_ref')->unique();
            $table->foreignId('evacuation_event_id')->constrained();
            $table->unsignedBigInteger('listed_family_remote_id');
            $table->string('family_label', 200);
            $table->unsignedSmallInteger('members_count');
            // At a center of the barangay, or in a private house.
            $table->foreignId('evacuation_center_id')->nullable()->constrained();
            $table->string('host_name', 150)->nullable();
            $table->unsignedBigInteger('host_purok_remote_id')->nullable();
            $table->string('host_address', 255)->nullable();
            $table->unsignedBigInteger('registered_family_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->string('sync_state', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_list_check_ins');
        Schema::table('puroks', function (Blueprint $table) {
            $table->dropColumn(['high_risk_flood', 'high_risk_landslide']);
        });
    }
};
