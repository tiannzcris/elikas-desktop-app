<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Families staying in a private house (with relatives or friends, outside
 * any evacuation center), added on this device and waiting to be sent
 * (POST /barangays/{id}/private-house-families, with client_ref).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_private_house_families', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_ref')->unique();
            $table->foreignId('evacuation_event_id')->constrained();
            $table->unsignedBigInteger('barangay_remote_id');
            $table->json('head');
            $table->json('members');
            $table->string('host_name', 150);
            $table->string('host_address', 255)->nullable();
            $table->unsignedBigInteger('host_purok_remote_id')->nullable();
            $table->boolean('is_4ps_beneficiary')->default(false);
            $table->unsignedBigInteger('remote_family_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->string('sync_state', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_private_house_families');
    }
};
