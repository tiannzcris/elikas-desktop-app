<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Supports EcBoardEntryController::store() creating a real local
    // Family+Evacuee for a "new household" Add Evacuee submission, so
    // that household becomes selectable as "existing" for a subsequent
    // entry at the same center -- confirmed missing before this (see
    // EcBoardEntry's own docblock on originated_household below).
    //
    // date_of_birth becomes nullable because this specific Evacuee row
    // is a LOCAL-ONLY placeholder (a real name, so the household picker
    // shows something better than "Household #N") that is NEVER
    // independently synced via /families/register -- confirmed against
    // the real backend's RegisterFamilyRequest, which requires
    // date_of_birth AND contact_number for every member, neither of
    // which "Add Evacuee" ever collects. This household instead syncs
    // through the EXISTING /evacuation-centers/{id}/evacuees endpoint
    // (household_mode: 'new'), exactly as it already did before this
    // change -- see families.created_via_ec_board below.
    public function up(): void
    {
        Schema::table('evacuees', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->change();
        });

        Schema::table('families', function (Blueprint $table) {
            // Marks a Family created as an EC Board "new household"
            // placeholder -- excluded from the normal registerFamily()
            // sync loop (it would fail that endpoint's validation), since
            // it instead syncs implicitly via its originating
            // ec_board_entries row's own addEvacuee() call.
            $table->boolean('created_via_ec_board')->default(false)->after('is_4ps_beneficiary');
        });

        Schema::table('ec_board_entries', function (Blueprint $table) {
            // True only on the ONE entry whose own submission created its
            // linked household_family_local_id Family -- every OTHER
            // entry that later picks that same household as "existing"
            // leaves this false. Without this distinction, toSyncPayload()
            // can't tell "sync me as household_mode=new" (this entry IS
            // the household) apart from "sync me as household_mode=
            // existing, once my family has synced" (a different person
            // added to an already-known household) -- conflating them
            // would either re-register the same person twice on the
            // server, or make an EC-Board-created family's own household
            // permanently unsyncable (it has no OTHER path to a
            // registerFamily() call, by design -- see families.
            // created_via_ec_board above).
            $table->boolean('originated_household')->default(false)->after('existing_household_remote_id');
        });
    }

    public function down(): void
    {
        Schema::table('ec_board_entries', function (Blueprint $table) {
            $table->dropColumn('originated_household');
        });

        Schema::table('families', function (Blueprint $table) {
            $table->dropColumn('created_via_ec_board');
        });

        Schema::table('evacuees', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable(false)->change();
        });
    }
};
