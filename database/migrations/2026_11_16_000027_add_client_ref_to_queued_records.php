<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * client_ref: one UUID per queued record, sent with every try. The central
 * server keeps its first answer per client_ref and returns it for a repeat,
 * so a retry after a lost connection never makes a second record
 * (elikas-backend docs/client-ref-api.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['ec_board_entries', 'families'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->uuid('client_ref')->nullable()->unique();
            });
            DB::table($table)->whereNull('client_ref')->orderBy('id')->each(function ($row) use ($table) {
                DB::table($table)->where('id', $row->id)->update(['client_ref' => (string) Str::uuid()]);
            });
        }
    }

    public function down(): void
    {
        foreach (['ec_board_entries', 'families'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropUnique(['client_ref']);
                $t->dropColumn('client_ref');
            });
        }
    }
};
