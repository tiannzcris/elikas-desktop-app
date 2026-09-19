<?php

namespace App\Http\Controllers;

use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\Family;
use App\Models\LocalAuth;
use App\Services\CentralApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AuthController extends Controller
{
    public function showLogin()
    {
        // Already logged in on this device -- no need to show the login
        // screen again (which would require internet); straight to work.
        if (LocalAuth::current()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    public function login(Request $request, CentralApiService $api)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        try {
            $result = $api->login($validated['email'], $validated['password']);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['login' => $e->getMessage()])->withInput(['email' => $validated['email']]);
        }

        // Only one row ever exists here -- whoever is currently logged in
        // on this device. A fresh login always replaces it.
        LocalAuth::query()->delete();
        LocalAuth::create([
            'remote_user_id' => $result['user']['id'],
            'name' => $result['user']['name'],
            'email' => $result['user']['email'],
            'role' => $result['user']['role'],
            'barangay_id' => $result['user']['barangay']['id'] ?? null,
            'barangay_name' => $result['user']['barangay']['name'] ?? null,
            'api_token' => $result['token'],
            'logged_in_at' => now(),
        ]);

        // We know we have internet right now (login just succeeded), so
        // pull current reference data immediately rather than making the
        // user do it as a separate manual step.
        try {
            $this->refreshReferenceData($api, $result['token']);
        } catch (\RuntimeException $e) {
            // Login itself still succeeds -- lacking a connection right
            // after login is a normal, expected state this app is built
            // around, so this must never block reaching the dashboard.
            // But silently swallowing this previously let a PARTIAL
            // refresh (see pruneStale()'s own docblock -- a real incident:
            // an interrupted refresh left evacuation_centers 54 stale rows
            // out of date against a real 39, entirely unnoticed) pass as
            // an ordinary successful login with zero indication the cache
            // might now be incomplete or wrong. Surfaced instead, so a
            // real refresh failure is visible immediately rather than
            // discovered later as unexplained missing/mismatched data.
            return redirect()->route('dashboard')->with(
                'referenceDataWarning',
                'Logged in, but refreshing reference data failed -- some barangays/events/centers on this device may be out of date. '.$e->getMessage()
            );
        }

        return redirect()->route('dashboard');
    }

    public function logout()
    {
        LocalAuth::query()->delete();

        return redirect()->route('login');
    }

    /**
     * Manual "Refresh reference data" action -- for when the device
     * regains internet sometime after the initial login, and the user
     * wants current barangays/events/centers without logging out and back in.
     */
    public function refreshReferenceDataAction(CentralApiService $api)
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }

        try {
            $this->refreshReferenceData($api, $auth->api_token);

            return back()->with('status', 'Reference data refreshed successfully.');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['refresh' => $e->getMessage()]);
        }
    }

    private function refreshReferenceData(CentralApiService $api, string $token): void
    {
        $data = $api->fetchReferenceData($token);

        $barangayRemoteIds = [];
        foreach ($data['barangays'] as $b) {
            Barangay::updateOrCreate(['remote_id' => $b['id']], ['name' => $b['name']]);
            $barangayRemoteIds[] = $b['id'];
        }
        // Barangays have no ec_board_entries FK of their own (an entry
        // only ever points at an event/center, never a barangay directly
        // -- see EcBoardEntry's own fillable columns), so the families-
        // only guard is already complete here.
        $this->pruneStale(Barangay::class, $barangayRemoteIds, 'barangay_id');

        $eventRemoteIds = [];
        foreach ($data['events'] as $e) {
            EvacuationEvent::updateOrCreate(['remote_id' => $e['id']], [
                'name' => $e['name'],
                'event_type' => $e['event_type'],
                'status' => $e['status'],
            ]);
            $eventRemoteIds[] = $e['id'];
        }
        $this->pruneStale(EvacuationEvent::class, $eventRemoteIds, 'evacuation_event_id', EcBoardEntry::class, 'evacuation_event_id');

        $centerRemoteIds = [];
        foreach ($data['centers'] as $c) {
            EvacuationCenter::updateOrCreate(['remote_id' => $c['id']], [
                'barangay_remote_id' => $c['barangay_id'],
                'name' => $c['name'],
                'status' => $c['status'],
            ]);
            $centerRemoteIds[] = $c['id'];
        }
        $this->pruneStale(EvacuationCenter::class, $centerRemoteIds, 'evacuation_center_id', EcBoardEntry::class, 'evacuation_center_id');

        // The EC Board's "as of last sync" breakdown is deliberately NOT
        // refreshed here -- the real central endpoint (quick-count) is
        // scoped to one center+event per call, not a bulk "all centers"
        // list, so pulling it for every cached center on every login/
        // refresh would mean N requests for centers nobody may even be
        // looking at. It's fetched on demand instead, only for the one
        // center+event actually being viewed -- see
        // EvacuationCenterController::show() and
        // CentralApiService::fetchCenterQuickCount().
    }

    /**
     * Removes local cache rows the central server no longer returns --
     * renamed away via a delete-and-reseed (new remote id), decommissioned,
     * or otherwise deleted upstream. updateOrCreate() above only ever adds
     * or updates, so without this step a removed remote record lives on in
     * the local cache forever and keeps showing up (e.g. as a stale,
     * no-longer-current evacuation center) in the registration form.
     *
     * Two safety guards:
     *  - Skip entirely if the server returned zero ids for this batch.
     *    whereNotIn() against an empty array matches every row (see
     *    Grammar::whereNotIn), so acting on an empty response here would
     *    wipe the whole local cache table -- far more likely a transient
     *    or malformed response than "the server truly has zero barangays
     *    now".
     *  - Never delete a row a LOCAL record still points to (synced or
     *    not) -- families.*_id AND ec_board_entries.*_id columns are BOTH
     *    real foreign keys with no cascade action defined (confirmed: a
     *    real device hit exactly this -- pruning an event still
     *    referenced by two ec_board_entries threw a genuine SQLite
     *    "FOREIGN KEY constraint failed", which aborted the rest of this
     *    refresh entirely, silently, leaving evacuation_centers stuck 54
     *    rows stale against the real 39). $foreignModelColumn is optional
     *    since barangays have no ec_board_entries column pointing at them
     *    at all (an entry only ever references an event/center, never a
     *    barangay directly) -- passing null there is correct, not an
     *    oversight.
     *
     * A protected row is still STALE, though -- the server no longer
     * returns it at all (not merely "closed" there), so if this model
     * tracks a status column (events/centers; barangays don't), it gets
     * force-closed here instead of being left stuck showing whatever
     * status it had the last time it WAS in a fresh fetch. Confirmed on a
     * real device: an event removed entirely from the server stayed
     * selectable in every "Add Evacuee"/registration dropdown
     * indefinitely, still reading "active" from before local records
     * started protecting it from deletion -- every one of those
     * dropdowns already filters on status != 'closed', so this alone is
     * enough to correctly drop it from all of them at once.
     */
    private function pruneStale(string $modelClass, array $currentRemoteIds, string $familyColumn, ?string $foreignModel = null, ?string $foreignModelColumn = null): void
    {
        if (empty($currentRemoteIds)) {
            return;
        }

        $referencedLocalIds = Family::whereNotNull($familyColumn)->pluck($familyColumn);

        if ($foreignModel && $foreignModelColumn) {
            $referencedLocalIds = $referencedLocalIds->merge(
                $foreignModel::whereNotNull($foreignModelColumn)->pluck($foreignModelColumn)
            );
        }

        $referencedLocalIds = $referencedLocalIds->unique();

        $stale = $modelClass::whereNotIn('remote_id', $currentRemoteIds);

        if (Schema::hasColumn((new $modelClass)->getTable(), 'status')) {
            (clone $stale)->whereIn('id', $referencedLocalIds)->update(['status' => 'closed']);
        }

        (clone $stale)->whereNotIn('id', $referencedLocalIds)->delete();
    }
}
