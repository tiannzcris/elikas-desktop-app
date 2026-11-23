<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts (docs/backend/apps-api-2026-11-23.md 1): the username staff can
 * sign in with, and whether the account is on a temporary password that
 * must be changed before anything else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_auth', function (Blueprint $table) {
            $table->string('username', 100)->nullable();
            $table->boolean('must_change_password')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('local_auth', function (Blueprint $table) {
            $table->dropColumn(['username', 'must_change_password']);
        });
    }
};