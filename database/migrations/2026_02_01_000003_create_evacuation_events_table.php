<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Read-only local cache, same purpose as barangays above -- only the
    // fields the registration form actually needs to display and submit.
    public function up(): void
    {
        Schema::create('evacuation_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('remote_id')->unique();
            $table->string('name', 150);
            $table->string('event_type', 30);
            $table->string('status', 30);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evacuation_events');
    }
};
