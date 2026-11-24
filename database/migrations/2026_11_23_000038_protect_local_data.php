<?php

use App\Security\LocalDataEncryption;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2.1.0 data protection (docs/local-data-protection.md):
 *  - local_auth.password_hash: signing in again after inactivity works
 *    offline (a bcrypt hash of the password last used online);
 *  - local_auth.locked_at: signed out of the screen after inactivity;
 *  - personal data encrypted at rest (LocalDataEncryption::protectExisting():
 *    a backup first, then every value encrypted and checked).
 *
 * Safe to run again (2.1.1): in 2.1.0 this migration added the columns and
 * then hung at the data key (see LocalDataKey::native()), so many devices
 * have the columns without this migration being recorded. Each column is
 * added only when missing. When the key cannot be opened in this process,
 * the encryption is left to the running app (LocalDataEncryption::pending()).
 */
return new class extends Migration
{
    // VACUUM INTO (the backup) cannot run inside a transaction; the data
    // step has its own.
    public $withinTransaction = false;

    public function up(): void
    {
        foreach (['password_hash' => 'text', 'locked_at' => 'timestamp'] as $column => $type) {
            if (! Schema::hasColumn('local_auth', $column)) {
                Schema::table('local_auth', fn (Blueprint $table) => $table->{$type}($column)->nullable());
            }
        }

        app(LocalDataEncryption::class)->protectExistingOrDefer('before-2.1.0-encryption');
    }

    public function down(): void
    {
        // Decrypting is not offered: restore the backup instead
        // (php artisan elikas:local-data restore <file>).
        Schema::table('local_auth', function (Blueprint $table) {
            $table->dropColumn(['password_hash', 'locked_at']);
        });
    }
};
