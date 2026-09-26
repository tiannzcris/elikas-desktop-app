<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Household-level answers behind the Child-Headed and Single-Headed
    // Family sectoral rows -- mirrors the central server's own
    // 2026_09_26_000001_add_household_head_fields_to_families_table. All
    // nullable: null means "not yet known", never "no".
    public function up(): void
    {
        Schema::table('families', function (Blueprint $table) {
            // The central server's own families.name (Add Evacuee's
            // "Household head's name"). Lets a household whose head is
            // someone else still be labelled without inventing a
            // placeholder head member with a guessed sex.
            $table->string('name', 150)->nullable()->after('home_address');
            $table->boolean('is_single_headed')->nullable()->after('is_4ps_beneficiary');
            // Used only while no head is linked; a linked head's own age
            // group always wins.
            $table->boolean('head_is_minor')->nullable()->after('is_single_headed');
            // Used only while no head is linked; a linked head's own sex
            // always wins.
            $table->string('head_sex', 6)->nullable()->after('head_is_minor');
            // The Add Evacuee entry recorded as this household's head -- this
            // device's equivalent of the server's head_of_family_evacuee_id
            // (an EC Board person only ever exists here as an entry). Null =
            // no head linked yet. Plain column, no FK: unlinking on delete is
            // done explicitly (EcBoardEntryController::destroy()).
            $table->unsignedBigInteger('head_ec_board_entry_id')->nullable()->index()->after('head_sex');
        });

        Schema::table('ec_board_entries', function (Blueprint $table) {
            // "This person is the household head" -- for a new household,
            // or for an existing one with no head linked yet (the real head
            // arriving later).
            $table->boolean('head_is_self')->default(false)->after('age_bracket');
        });
    }

    public function down(): void
    {
        Schema::table('ec_board_entries', function (Blueprint $table) {
            $table->dropColumn('head_is_self');
        });

        Schema::table('families', function (Blueprint $table) {
            $table->dropIndex(['head_ec_board_entry_id']);
            $table->dropColumn(['name', 'is_single_headed', 'head_is_minor', 'head_sex', 'head_ec_board_entry_id']);
        });
    }
};
