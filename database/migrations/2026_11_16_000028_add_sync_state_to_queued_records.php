<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sync_state of a record not yet accepted by the server: null = waiting to
 * be sent; failed = the server refused it (sync_error says why) and it waits
 * for the user (try again, edit or remove); decision = the server asks a
 * question (a possible duplicate family).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['ec_board_entries', 'families'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('sync_state', 20)->nullable()->after('sync_error');
            });
        }
    }

    public function down(): void
    {
        foreach (['ec_board_entries', 'families'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('sync_state');
            });
        }
    }
};
