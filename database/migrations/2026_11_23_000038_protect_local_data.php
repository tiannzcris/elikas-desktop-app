<?php

use App\Security\LocalDataEncryption;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 2.1.0 data protection (docs/local-data-protection.md):
 *  - local_auth.password_hash: signing in again after inactivity works
 *    offline (a bcrypt hash of the password last used online);
 *  - local_auth.locked_at: signed out of the screen after inactivity;
 *  - personal data encrypted at rest: a backup first, then every value
 *    encrypted and checked (LocalDataEncryption::encryptExisting()).
 */
return new class extends Migration
{
    // VACUUM INTO (the backup) cannot run inside a transaction; the data
    // step has its own.
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('local_auth', function (Blueprint $table) {
            $table->text('password_hash')->nullable();
            $table->timestamp('locked_at')->nullable();
        });

        $encryption = app(LocalDataEncryption::class);
        $backup = $encryption->backup('before-2.1.0-encryption');
        $counts = $encryption->encryptExisting();

        Log::info('Local data encrypted at rest.', ['backup' => $backup, 'values' => array_sum($counts)]);
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
