<?php

namespace App\Http\Controllers;

use App\Exceptions\CentralApiException;
use App\Models\Barangay;
use App\Models\CenterFamilySnapshot;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\Family;
use App\Models\LocalAuth;
use App\Models\Purok;
use App\Models\ReturningFamilySnapshot;
use App\Services\CentralApiService;
use App\Services\CityViews;
use App\Services\SelectedEvent;
use App\Services\LocalData;
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

    public function login(Request $request, CentralApiService $api, LocalData $local)
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
        // Accounts without operational access (the administrator) work on
        // the web only: revoke the token just issued and keep nothing.
        $candidate = new LocalAuth(LocalAuth::attributesFromUser($result['user']));
        if (! $candidate->hasOperationsAccess()) {
            $api->logout($result['token']);

            return redirect()->route('use-web');
        }

        if ($owner = $local->blocksLoginOf((int) $result['user']['id'])) {
            $api->logout($result['token']);

            return back()->withErrors(['login' => "Records made by {$owner['name']} ({$owner['email']}) are still waiting to be sent from this device. Log in as {$owner['name']} and sync them first."])
                ->withInput(['email' => $validated['email']]);
        }

        LocalAuth::query()->delete();
        // The selected event goes back to the default at every login (the
        // server does the same); an older choice made here is not sent.
        app(SelectedEvent::class)->reset();
        LocalAuth::create(LocalAuth::attributesFromUser($result['user']) + [
            'api_token' => $result['token'],
            'logged_in_at' => now(),
            'identity_checked_at' => now(),
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

    /** For accounts that have no work on the desktop (the administrator). */
    public function useWeb(CentralApiService $api)
    {
        return view('auth.use-web', ['webUrl' => $api->webUrl()]);
    }

    /**
     * Revokes the token on the server (when reachable), keeps queued
     * records for this account's next login, and removes copies of data
     * already safe on the server.
     */
    public function logout(CentralApiService $api, LocalData $local)
    {
        if ($auth = LocalAuth::current()) {
            $api->logout($auth->api_token);
            $local->closeSession($auth);
        }
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
            $this->refreshIdentity($api, $auth);
            $this->refreshReferenceData($api, $auth->api_token);

            return back()->with('status', 'Reference data refreshed successfully.');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['refresh' => $e->getMessage()]);
        }
    }

    /**
     * Brings the stored name, barangay and permissions up to date from
     * GET /auth/me (a role or barangay may have changed on the server).
     */
    public static function refreshIdentity(CentralApiService $api, LocalAuth $auth): void
    {
        $user = $api->me($auth->api_token);
        if (! empty($user['id'])) {
            $auth->update(LocalAuth::attributesFromUser($user) + ['identity_checked_at' => now()]);
        }
    }

    private function refreshReferenceData(CentralApiService $api, string $token): void
    {
        // A choice of event made offline goes to the server first.
        if ($auth = LocalAuth::current()) {
            app(SelectedEvent::class)->push($auth);
        }

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
                'is_demo' => (bool) ($e['is_demo'] ?? false),
                'start_date' => $e['start_date'] ?? null,
            ]);
            $eventRemoteIds[] = $e['id'];
        }
        $this->pruneStale(EvacuationEvent::class, $eventRemoteIds, 'evacuation_event_id', EcBoardEntry::class, 'evacuation_event_id');

        // The event this user works on, as the server remembers it (chosen
        // on the web or here, or picked by the server).
        try {
            $selection = $api->send('get', 'selected-event', $token, [], 10)['data'] ?? null;
            if (is_array($selection)) {
                app(SelectedEvent::class)->fromServer($selection['selected'] ?? null, (bool) ($selection['chosen'] ?? false));
            }
        } catch (CentralApiException $e) {
            if ($e->isOffline() || $e->kind === CentralApiException::UNAUTHENTICATED) {
                throw $e;
            }
            // An older server without the switcher: the device picks.
        } catch (\RuntimeException $e) {
            // Not essential to the refresh.
        }

        // The server already lists only the centers this user may see (an
        // official: their own barangay's; never a rejected one). Kept to
        // the official's own barangay here too, in case of an older server.
        $ownBarangay = LocalAuth::current()?->isBarangayOfficial() ? (int) LocalAuth::current()->barangay_id : null;
        $centerRemoteIds = [];
        foreach ($data['centers'] as $c) {
            $barangayId = (int) ($c['barangay_id'] ?? $c['barangay']['id'] ?? 0);
            if (($c['approval_status'] ?? 'approved') === 'rejected'
                || ($ownBarangay && $barangayId !== $ownBarangay)) {
                continue;
            }
            EvacuationCenter::updateOrCreate(['remote_id' => $c['id']], [
                'barangay_remote_id' => $barangayId,
                'name' => $c['name'],
                'status' => $c['status'],
                'approval_status' => $c['approval_status'] ?? 'approved',
                'capacity_persons' => $c['capacity_persons'] ?? null,
                'capacity_source' => $c['capacity_source'] ?? (empty($c['capacity_persons']) ? 'unknown' : 'official'),
                'capacity_basis' => $c['capacity_basis'] ?? null,
                'current_occupancy' => $c['current_occupancy'] ?? null,
                'effective_status' => $c['effective_status'] ?? null,
            ]);
            $centerRemoteIds[] = $c['id'];
        }
        $this->pruneStale(EvacuationCenter::class, $centerRemoteIds, 'evacuation_center_id', EcBoardEntry::class, 'evacuation_center_id');

        // The official's own barangay's puroks, for a new household offline.
        $auth = LocalAuth::current();
        if ($auth?->isBarangayOfficial() && $auth->barangay_id && $auth->can('puroks.view')) {
            $puroks = $api->fetchPuroks($token, (int) $auth->barangay_id);
            foreach ($puroks as $p) {
                Purok::updateOrCreate(['remote_id' => $p['id']], Purok::attributesFromServer($p));
            }
            Purok::whereNotIn('remote_id', array_column($puroks, 'id'))->delete();

            $this->prefetchFamiliesAtCenters($api, $token, (int) $auth->barangay_id);
            if ($auth->can('families.re_enroll')) {
                $this->prefetchReturningFamilies($api, $token, (int) $auth->barangay_id);
            }
            // Families in private houses, for the EC Board's card and counts.
            app(\App\Services\PrivateHouses::class)->refresh($auth);
            // Families to evacuate, for reading and checking in offline.
            if ($auth->can('families_to_evacuate.manage')) {
                app(\App\Services\FamiliesToEvacuate::class)->refreshOpen($auth);
            }
            // The final count, for reading offline.
            if ($auth->can('count_submissions.submit')) {
                app(\App\Services\BarangayFinalCount::class)->refreshOpen($auth);
            }
        }

        // CSWD: Monitoring and Final counts, for reading offline.
        if ($auth?->isCityWide()) {
            app(CityViews::class)->refresh($auth);
        }

        // Transfer requests of the selected event, for reading offline.
        if ($auth && ($auth->can('transfers.request') || $auth->can('transfers.receive') || $auth->can('transfers.manage'))) {
            try {
                app(\App\Services\Transfers::class)->refresh($auth);
            } catch (CentralApiException $e) {
                if ($e->isOffline() || $e->kind === CentralApiException::UNAUTHENTICATED) {
                    throw $e;
                }
            }
        }

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
     * Keeps "Already here" ready offline: the families checked in at each
     * of the official's open centers, for each open event. One center that
     * can't be read (gone, refused) doesn't stop the rest; no connection does.
     */
    private function prefetchFamiliesAtCenters(CentralApiService $api, string $token, int $barangayRemoteId): void
    {
        $centers = EvacuationCenter::where('barangay_remote_id', $barangayRemoteId)->where('status', '!=', 'closed')->get();
        $events = EvacuationEvent::where('status', '!=', 'closed')->get();

        foreach ($centers as $center) {
            foreach ($events as $event) {
                try {
                    CenterFamilySnapshot::fetch($api, $token, $center, $event);
                } catch (CentralApiException $e) {
                    if ($e->isOffline() || $e->kind === CentralApiException::UNAUTHENTICATED) {
                        throw $e;
                    }
                }
            }
        }
    }

    /**
     * Keeps Returning families ready offline: for each open event, the past
     * events and their re-enrollable families of the official's barangay
     * (the same list from any of its centers). A refusal keeps the old copy.
     */
    private function prefetchReturningFamilies(CentralApiService $api, string $token, int $barangayRemoteId): void
    {
        $center = EvacuationCenter::where('barangay_remote_id', $barangayRemoteId)->where('status', '!=', 'closed')
            ->where('approval_status', '!=', 'rejected')->orderBy('id')->first();
        if (! $center) {
            return;
        }

        foreach (EvacuationEvent::where('status', '!=', 'closed')->get() as $event) {
            try {
                $list = $api->fetchReturningFamilies($token, $center->remote_id, $event->remote_id);
                $pastEvents = $list['past_events'] ?? [];
                $families = [];
                foreach ($pastEvents as $past) {
                    $families[(string) $past['id']] = $api->fetchReturningFamilies($token, $center->remote_id, $event->remote_id, $past['id'])['families'] ?? [];
                }
                ReturningFamilySnapshot::updateOrCreate(['evacuation_event_id' => $event->id], [
                    'past_events' => $pastEvents, 'families_by_event' => $families, 'fetched_at' => now(),
                ]);
            } catch (CentralApiException $e) {
                if ($e->isOffline() || $e->kind === CentralApiException::UNAUTHENTICATED) {
                    throw $e;
                }
            }
        }
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
