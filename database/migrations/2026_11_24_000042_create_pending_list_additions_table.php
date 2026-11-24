<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Families added to the families-to-evacuate list on this computer, waiting
 * to be sent (POST /families-to-evacuate with client_ref,
 * docs/backend/apps-api-2026-11-24.md 5.2). A check-in of such a family
 * waits for it (pending_list_check_ins.pending_list_addition_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Each step only when missing: safe to run again (2.1.1).
        if (! Schema::hasTable('pending_list_additions')) {
            Schema::create('pending_list_additions', function (Blueprint $table) {
                $table->id();
                $table->uuid('client_ref')->unique();
                $table->foreignId('evacuation_event_id')->constrained();
                $table->unsignedBigInteger('purok_remote_id')->nullable();
                $table->unsignedBigInteger('source_family_id')->nullable();
                $table->text('head_first_name')->nullable();
                $table->text('head_last_name')->nullable();
                $table->string('head_sex', 10)->nullable();
                $table->unsignedSmallInteger('members_count')->default(1);
                $table->text('contact_number')->nullable();
                $table->text('note')->nullable();
                // The name shown until the server's row arrives (a past family's head).
                $table->text('label');
                $table->unsignedBigInteger('listed_family_remote_id')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->text('sync_error')->nullable();
                $table->string('sync_state', 20)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('pending_list_check_ins', 'pending_list_addition_id')) {
            Schema::table('pending_list_check_ins', function (Blueprint $table) {
                $table->unsignedBigInteger('listed_family_remote_id')->nullable()->change();
                $table->foreignId('pending_list_addition_id')->nullable()->constrained('pending_list_additions')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('pending_list_check_ins', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pending_list_addition_id');
        });
        Schema::dropIfExists('pending_list_additions');
    }
};
