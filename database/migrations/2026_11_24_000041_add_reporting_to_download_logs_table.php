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
        Schema::table('download_logs', function (Blueprint $table) {
            $table->uuid('client_ref')->nullable()->unique();
            $table->string('file_type', 50)->nullable();
            $table->string('action', 10)->default('saved');
            $table->unsignedBigInteger('report_remote_id')->nullable();
            $table->text('sync_error')->nullable();
            $table->string('sync_state', 20)->nullable();
        });

        $types = ['ec-board' => 'ec_information_board', 'family-list' => 'evacuees', 'relief-sheet' => 'relief_families',
            'families-to-evacuate' => 'families_to_evacuate', 'waiting-records' => 'waiting_records'];
        foreach (DB::table('download_logs')->get(['id', 'document']) as $row) {
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
