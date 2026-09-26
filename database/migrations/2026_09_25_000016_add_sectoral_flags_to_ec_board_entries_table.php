<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Optional sectoral flags for the ONE person an "Add Evacuee" entry
    // records, mirroring the central server's own evacuees columns and its
    // addEvacuee() endpoint. All nullable, default null: null means "not
    // recorded" (the box was left unticked), NOT "no" -- only a true flag
    // counts toward the EC Board's live sectoral figures. Existing entries
    // simply keep null for all six.
    public function up(): void
    {
        Schema::table('ec_board_entries', function (Blueprint $table) {
            $table->boolean('is_pwd')->nullable()->after('age_bracket');
            $table->boolean('is_pregnant')->nullable()->after('is_pwd');
            $table->boolean('is_lactating')->nullable()->after('is_pregnant');
            $table->boolean('is_solo_parent')->nullable()->after('is_lactating');
            $table->boolean('is_indigenous_person')->nullable()->after('is_solo_parent');
            $table->boolean('is_4ps_beneficiary')->nullable()->after('is_indigenous_person');
        });
    }

    public function down(): void
    {
        Schema::table('ec_board_entries', function (Blueprint $table) {
            $table->dropColumn(['is_pwd', 'is_pregnant', 'is_lactating', 'is_solo_parent', 'is_indigenous_person', 'is_4ps_beneficiary']);
        });
    }
};
