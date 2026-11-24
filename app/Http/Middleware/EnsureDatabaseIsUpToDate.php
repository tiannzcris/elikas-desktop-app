<?php

namespace App\Http\Middleware;

use App\Services\PendingMigrationChecker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks every route behind an explicit "Update Now" screen whenever the
 * local database is behind this app version's migrations -- see
 * PendingMigrationChecker's docblock for why this is a click-to-confirm
 * gate, never a silent background migration.
 *
 * Runs ahead of everything else in the 'web' group (see bootstrap/app.php)
 * and applies to EVERY route, logged in or not: a schema mismatch can
 * affect tables auth itself depends on (local_auth), so this can't be
 * scoped to only "protected" pages the way LocalAuth's own per-controller
 * checks are.
 */
class EnsureDatabaseIsUpToDate
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('system.update-required') || $request->routeIs('system.update-required.run')) {
            return $next($request);
        }

        if (app(PendingMigrationChecker::class)->hasPendingMigrations()) {
            return redirect()->route('system.update-required');
        }

        // Encrypting the existing data had to wait for the running app (the
        // key opens only here): do it now, once. A failure is logged and
        // tried again at the next page; nothing is changed until it succeeds.
        $encryption = app(\App\Security\LocalDataEncryption::class);
        if ($encryption->pending()) {
            try {
                $encryption->protectExistingOrDefer('before-2.1.1-encryption');
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $next($request);
    }
}
