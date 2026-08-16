<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Read-only local cache of the central server's full evacuee/family
    // roster (GET /families), refreshed on every "All Evacuees" page load
    // while online -- distinct from the `families` table, which is this
    // device's own offline registration queue and not necessarily on the
    // server yet at all. remote_id is the central server's family id; id
    // is just SQLite's local rowid.
    public function up(): void
    {
        Schema::create('evacuee_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('remote_id')->unique();
            $table->string('head_name', 150)->nullable();
            $table->string('barangay_name', 100)->nullable();
            $table->unsignedInteger('member_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evacuee_records');
    }
};
