<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Every saved file is reported to the server (POST /file-downloads,
 * docs/backend/apps-api-2026-11-24.md 4), through the queue with a
 * client_ref so a report sent again is logged once. reported_at is set once
 * the server has it. Files recorded before 2.1.0 are reported too.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Each column only when missing: safe to run again (2.1.1).
        $columns = [
            'client_ref' => fn (Blueprint $t) => $t->uuid('client_ref')->nullable()->unique(),
            'file_type' => fn (Blueprint $t) => $t->string('file_type', 50)->nullable(),
            'action' => fn (Blueprint $t) => $t->string('action', 10)->default('saved'),
            'report_remote_id' => fn (Blueprint $t) => $t->unsignedBigInteger('report_remote_id')->nullable(),
            'sync_error' => fn (Blueprint $t) => $t->text('sync_error')->nullable(),
            'sync_state' => fn (Blueprint $t) => $t->string('sync_state', 20)->nullable(),
        ];
        foreach ($columns as $column => $add) {
            if (! Schema::hasColumn('download_logs', $column)) {
                Schema::table('download_logs', $add);
            }
        }

        $types = ['ec-board' => 'ec_information_board', 'family-list' => 'evacuees', 'relief-sheet' => 'relief_families',
            'families-to-evacuate' => 'families_to_evacuate', 'waiting-records' => 'waiting_records'];
        // Only rows without one yet.
        foreach (DB::table('download_logs')->whereNull('client_ref')->get(['id', 'document']) as $row) {
            DB::table('download_logs')->where('id', $row->id)->update(['client_ref' => (string) Str::uuid(), 'file_type' => $types[$row->document] ?? 'other']);
        }
    }

    public function down(): void
    {
        Schema::table('download_logs', function (Blueprint $table) {
            $table->dropUnique(['client_ref']);
            $table->dropColumn(['client_ref', 'file_type', 'action', 'report_remote_id', 'sync_error', 'sync_state']);
        });
    }
};
