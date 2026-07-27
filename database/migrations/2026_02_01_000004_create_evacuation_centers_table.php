<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Read-only local cache, deliberately simple: no spatial POINT column
    // (SQLite has no equivalent, and this offline companion doesn't do GIS
    // work anyway -- see Chapter 1's scope note). Plain lat/lng kept only
    // in case a future version wants to show a simple reference map.
    public function up(): void
    {
        Schema::create('evacuation_centers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('remote_id')->unique();
            $table->unsignedBigInteger('barangay_remote_id');
            $table->string('name', 150);
            $table->string('status', 30);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evacuation_centers');
    }
};
