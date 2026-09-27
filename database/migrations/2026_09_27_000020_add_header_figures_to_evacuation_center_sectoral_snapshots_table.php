<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The EC Information Board header's own figures -- No. of Families and
    // No. of Persons, cumulative and now -- which the central server's
    // quick-count response always carried but this cache never kept, plus
    // fetched_at: when that response last arrived, i.e. the board's
    // "As of". Null figures mean "not fetched since this column existed".
    public function up(): void
    {
        Schema::table('evacuation_center_sectoral_snapshots', function (Blueprint $table) {
            $table->unsignedInteger('families_cumulative')->nullable()->after('evacuation_event_id');
            $table->unsignedInteger('families_now')->nullable()->after('families_cumulative');
            $table->unsignedInteger('persons_cumulative')->nullable()->after('families_now');
            $table->unsignedInteger('persons_now')->nullable()->after('persons_cumulative');
            $table->timestamp('fetched_at')->nullable()->after('server_updated_at');
        });

        // A row cached before now was last fetched no earlier than it last
        // changed -- an honest lower bound, never fresher than the truth.
        DB::table('evacuation_center_sectoral_snapshots')->update(['fetched_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('evacuation_center_sectoral_snapshots', function (Blueprint $table) {
            $table->dropColumn(['families_cumulative', 'families_now', 'persons_cumulative', 'persons_now', 'fetched_at']);
        });
    }
};
