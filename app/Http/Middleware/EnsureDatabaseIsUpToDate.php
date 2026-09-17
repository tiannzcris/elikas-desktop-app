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

        return $next($request);
    }
}
