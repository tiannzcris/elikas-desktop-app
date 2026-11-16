<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Puroks (zones) of the official's own barangay, cached for offline use,
 * and the purok a new household was given on the EC Board.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('puroks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('remote_id')->unique();
            $table->unsignedBigInteger('barangay_remote_id')->index();
            $table->string('name', 100);
            $table->timestamps();
        });

        Schema::table('families', function (Blueprint $table) {
            $table->unsignedBigInteger('purok_remote_id')->nullable()->after('barangay_id');
        });
    }

    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->dropColumn('purok_remote_id');
        });
        Schema::dropIfExists('puroks');
    }
};
