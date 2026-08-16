<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Matches the home_address field already added on the web dashboard's
    // side of family registration -- optional, so nullable here too.
    public function up(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->string('home_address', 255)->nullable()->after('barangay_id');
        });
    }

    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->dropColumn('home_address');
        });
    }
};
