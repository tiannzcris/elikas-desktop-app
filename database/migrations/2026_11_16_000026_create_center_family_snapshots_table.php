<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The families with someone checked in at a center for an event, as the
 * central server last listed them (GET .../families): "Already here" and
 * check-out keep working offline. Wiped at logout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('center_family_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evacuation_center_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evacuation_event_id')->constrained()->cascadeOnDelete();
            $table->json('families');
            $table->timestamp('fetched_at');
            $table->timestamps();
            $table->unique(['evacuation_center_id', 'evacuation_event_id'], 'center_family_snapshot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('center_family_snapshots');
    }
};
