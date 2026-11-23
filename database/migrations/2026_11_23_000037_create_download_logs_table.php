<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every file with personal information saved from this app (Print and
 * download): what, for which event and center, by whom, when, and where.
 * Kept on this computer: the copied API notes have no endpoint to report
 * downloads to the server yet (reported_at stays empty until one exists).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('download_logs', function (Blueprint $table) {
            $table->id();
            $table->string('document', 40);
            $table->string('format', 10);
            $table->string('file_name', 200);
            $table->unsignedBigInteger('evacuation_event_remote_id')->nullable();
            $table->unsignedBigInteger('evacuation_center_remote_id')->nullable();
            $table->unsignedBigInteger('user_remote_id');
            $table->string('user_name', 150);
            $table->string('saved_to', 500)->nullable();
            $table->boolean('generated_offline')->default(true);
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_logs');
    }
};
