<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The signed-in user's named permissions from /auth/login and /auth/me
 * (the server's App\Auth\Permissions), so screens follow permissions, not
 * role names, and keep doing so offline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_auth', function (Blueprint $table) {
            $table->text('permissions')->nullable()->after('role');
            $table->string('role_display_name', 100)->nullable()->after('permissions');
            $table->string('status', 30)->nullable()->after('role_display_name');
            $table->timestamp('identity_checked_at')->nullable()->after('logged_in_at');
        });
    }

    public function down(): void
    {
        Schema::table('local_auth', function (Blueprint $table) {
            $table->dropColumn(['permissions', 'role_display_name', 'status', 'identity_checked_at']);
        });
    }
};
