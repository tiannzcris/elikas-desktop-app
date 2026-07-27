<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Read-only local cache of the central server's barangays, refreshed
    // whenever the device has internet -- exists purely so the
    // registration form's dropdown has data to show while offline.
    // remote_id is the source of truth; id is just SQLite's local rowid.
    public function up(): void
    {
        Schema::create('barangays', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('remote_id')->unique();
            $table->string('name', 100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barangays');
    }
};
