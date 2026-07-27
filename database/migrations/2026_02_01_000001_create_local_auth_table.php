<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // This is a single-user field device (one barangay official's laptop),
    // not a multi-account system -- so instead of replicating the central
    // server's full users/roles tables locally, this just caches whoever
    // is currently logged in: their identity, their Sanctum token (needed
    // to call the central API when syncing), and when they last logged in.
    // Expect exactly zero or one row in this table at any time.
    public function up(): void
    {
        Schema::create('local_auth', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('remote_user_id')->comment('The user ID on the central server');
            $table->string('name', 150);
            $table->string('email', 150);
            $table->string('role', 50);
            $table->unsignedBigInteger('barangay_id')->nullable()->comment('Central server barangay ID');
            $table->string('barangay_name', 100)->nullable();
            $table->text('api_token');
            $table->timestamp('logged_in_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_auth');
    }
};
