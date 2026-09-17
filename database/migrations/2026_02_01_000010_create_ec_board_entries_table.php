<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The EC Board's offline "Add Evacuee" fast-tally queue -- mirrors the
    // families table's own sync-tracking shape exactly (remote_id/synced_at/
    // sync_error), since this is the same offline-then-push pattern applied
    // to a different, lighter-weight kind of record (a headcount entry, not
    // a full household registration). Genuinely new local data: there is no
    // existing per-evacuee-detail cache to extend (evacuee_records only
    // stores one row per family, with no sex/age data at all).
    public function up(): void
    {
        Schema::create('ec_board_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evacuation_center_id')->constrained();
            $table->foreignId('evacuation_event_id')->constrained();
            $table->enum('sex', ['male', 'female']);
            // Not an enum: the bracket taxonomy below (see EcBoardEntry::
            // AGE_BRACKETS) is this app's own assumption, not yet confirmed
            // against the central server's accepted values -- a plain
            // string keeps a taxonomy change a validation-rule edit, not a
            // migration.
            $table->string('age_bracket', 20);

            // Household linkage -- exactly one of these two is set,
            // enforced in AddEvacueeRequest, not here (SQLite has no clean
            // "exactly one of" column constraint, and this app already
            // favors app-level validation over DB-level constraints for
            // this kind of cross-field rule -- see RegisterFamilyRequest's
            // head-of-family check).
            $table->foreignId('household_family_local_id')->nullable()->constrained('families');
            $table->string('new_household_head_name', 150)->nullable();

            // --- Sync tracking -- identical shape to families' own ---
            $table->unsignedBigInteger('remote_id')->nullable()->comment('ID assigned by the central server once synced');
            $table->timestamp('synced_at')->nullable()->comment('Null = not yet synced to the central server');
            $table->text('sync_error')->nullable()->comment('Last sync failure reason, if any, shown to the user');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ec_board_entries');
    }
};
