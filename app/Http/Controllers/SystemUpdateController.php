<?php

namespace App\Http\Controllers;

use App\Services\PendingMigrationChecker;
use Illuminate\Support\Facades\Artisan;

class SystemUpdateController extends Controller
{
    /**
     * The blocking screen EnsureDatabaseIsUpToDate redirects every route
     * to while migrations are pending. No LocalAuth check here -- this
     * must be reachable even before login works, since a schema mismatch
     * can affect the local_auth table itself.
     */
    public function show(PendingMigrationChecker $checker)
    {
        $pending = $checker->pendingMigrations();

        // Already caught up (e.g. updated from another window, or this
        // was reached right as a previous update finished) -- nothing to
        // block on, so don't show a stale prompt.
        if (empty($pending)) {
            return redirect()->route('dashboard');
        }

        return view('system.update-required', [
            'pendingCount' => count($pending),
        ]);
    }

    /**
     * Only ever reached by an explicit click on "Update Now" -- see this
     * app's own note on why nothing runs this automatically. Migrations
     * on this app's small local SQLite database run in well under a
     * second, so this stays a plain synchronous request/redirect rather
     * than a polled background job -- there is no meaningful "in
     * progress" state to show beyond the button's own disabled/"Updating…"
     * state while the request is in flight.
     */
    public function run(PendingMigrationChecker $checker)
    {
        try {
            Artisan::call('migrate', ['--force' => true]);
        } catch (\Throwable $e) {
            return back()->withErrors([
                'update' => 'The update failed: '.$e->getMessage().' Your data has not been changed -- you can safely try again.',
            ]);
        }

        // migrate() exiting without an exception doesn't strictly
        // guarantee every file ran (a migration can silently no-op on
        // some edge cases) -- re-checking here means a real failure
        // surfaces as a clear error on this screen, not as the app
        // silently continuing on a still-outdated schema.
        if ($checker->hasPendingMigrations()) {
            return back()->withErrors([
                'update' => 'The update did not fully complete. Your data has not been lost -- please try again, or seek help before continuing to use this device for this event.',
            ]);
        }

        return redirect()->route('dashboard')->with('status', 'App updated successfully.');
    }
}
