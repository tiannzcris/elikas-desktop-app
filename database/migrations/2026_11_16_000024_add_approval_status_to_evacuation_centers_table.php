<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CSWD approval of a center (server step 7): approved, pending (shown as
 * "Unverified") or rejected. A rejected center takes no evacuees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evacuation_centers', function (Blueprint $table) {
            $table->string('approval_status', 20)->default('approved')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('evacuation_centers', function (Blueprint $table) {
            $table->dropColumn('approval_status');
        });
    }
};
