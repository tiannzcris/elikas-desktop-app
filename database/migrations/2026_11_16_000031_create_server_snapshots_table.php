<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only copies of server pages for CSWD on the desktop: Monitoring
 * sections and Final counts, each kept with when it was fetched so the
 * page can say "As of ...". Wiped at logout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('payload');
            $table->timestamp('fetched_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_snapshots');
    }
};
