<?php

namespace App\Support;

/**
 * One `migrate` at a time on this computer. NativePHP runs `artisan
 * migrate` at every start of a new version, and the "update local data"
 * screen runs it too; two starts close together (2.1.0's frozen first start
 * made people click again) ran two at once, and the second failed on tables
 * the first had just made ("table local_auth already exists").
 *
 * The lock is a file in storage, held from the start of the command to its
 * end; the second run waits, then finds nothing left to do.
 */
final class MigrationLock
{
    /** @var resource|null */
    private static $handle = null;

    public static function path(): string
    {
        return storage_path('app/migrate.lock');
    }

    /** Waits for another migrate to finish (up to $seconds), then holds the lock. */
    public static function acquire(int $seconds = 300): void
    {
        if (self::$handle !== null) {
            return;
        }
        if (! is_dir(dirname(self::path()))) {
            @mkdir(dirname(self::path()), 0700, true);
        }
        $handle = fopen(self::path(), 'c');
        if ($handle === false) {
            return; // Storage not writable: nothing to lock with; migrate as before.
        }
        $deadline = microtime(true) + $seconds;
        while (! flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($handle);
                throw new \RuntimeException('Another update of the local data is still running. Close the app, open it again, and wait for it to start.');
            }
            usleep(250_000);
        }
        self::$handle = $handle;
    }

    public static function release(): void
    {
        if (self::$handle !== null) {
            flock(self::$handle, LOCK_UN);
            fclose(self::$handle);
            self::$handle = null;
        }
    }
}
