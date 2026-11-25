<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Center figures per event (docs/backend/apps-api-2026-11-center-figures-per-event.md):
 * everyone inside the center (all open events of the same kind) and the
 * server's ready-made note ("87 people here for Typhoon Drill").
 *
 * Sent records (Sync > Sent): who sent each record, and for an EC Board
 * entry the household the server put the person in, so a sent registration
 * can be corrected on the server later.
 */
return new class extends Migration
{
    private const SENT_TABLES = ['ec_board_entries', 'families', 'pending_check_outs', 'pending_re_enrollments',
        'pending_private_house_families', 'pending_list_check_ins', 'pending_list_additions', 'pending_handouts'];

    public function up(): void
    {
        // Each step only when missing: safe to run again (2.1.1).
        foreach (['total_occupancy' => fn (Blueprint $t) => $t->unsignedInteger('total_occupancy')->nullable(),
            'occupancy_note' => fn (Blueprint $t) => $t->string('occupancy_note', 255)->nullable()] as $column => $add) {
            if (! Schema::hasColumn('evacuation_centers', $column)) {
                Schema::table('evacuation_centers', $add);
            }
        }

        foreach (self::SENT_TABLES as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'sent_by')) {
                Schema::table($table, fn (Blueprint $t) => $t->string('sent_by', 150)->nullable());
            }
        }

        if (! Schema::hasColumn('ec_board_entries', 'family_remote_id')) {
            Schema::table('ec_board_entries', fn (Blueprint $t) => $t->unsignedBigInteger('family_remote_id')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('evacuation_centers', fn (Blueprint $t) => $t->dropColumn(['total_occupancy', 'occupancy_note']));
        Schema::table('ec_board_entries', fn (Blueprint $t) => $t->dropColumn('family_remote_id'));
        foreach (self::SENT_TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('sent_by'));
        }
    }
};
