<?php

namespace App\Services;

use Illuminate\Database\Migrations\Migrator;

/**
 * Detects a mismatch between what this app version's migration files
 * expect and what has actually been run against the local database --
 * the exact gap that caused today's real incident: a rebuilt app shipped
 * with two new migrations, but the persistent on-device database (which
 * survives across installs/updates, unlike the packaged app files) never
 * had them applied, so every EC Board page 500'd with "no such table".
 *
 * Deliberately does NOT run anything itself -- see
 * EnsureDatabaseIsUpToDate/SystemUpdateController for why this app never
 * auto-migrates silently: a migration force-interrupted mid-run (low
 * battery, a crash) on a field device during an active disaster could
 * leave it permanently unable to start, with no internet to get help.
 * This class only answers "is an explicit, user-confirmed update needed
 * right now" -- the update itself always requires a click.
 */
class PendingMigrationChecker
{
    public function __construct(private Migrator $migrator)
    {
    }

    /**
     * @return list<string> names of migrations this app's files define
     *                       that have not been recorded as run
     */
    public function pendingMigrations(): array
    {
        $files = $this->migrator->getMigrationFiles(database_path('migrations'));

        // No migrations table at all -- a genuinely fresh/empty database
        // (first launch, or one that failed before ever completing its
        // first migration) -- every file this app ships is pending.
        if (! $this->migrator->repositoryExists()) {
            return array_keys($files);
        }

        $ran = $this->migrator->getRepository()->getRan();

        return array_values(array_diff(array_keys($files), $ran));
    }

    /**
     * Any failure while even checking (a locked/corrupted database file,
     * a permissions issue, etc.) is treated as "needs attention" rather
     * than silently letting the app continue on a database it couldn't
     * actually verify -- the update screen's own attempt to migrate will
     * then surface whatever the real problem is with a clear error,
     * instead of the app crashing somewhere else entirely unexplained.
     */
    public function hasPendingMigrations(): bool
    {
        try {
            return count($this->pendingMigrations()) > 0;
        } catch (\Throwable $e) {
            return true;
        }
    }
}
