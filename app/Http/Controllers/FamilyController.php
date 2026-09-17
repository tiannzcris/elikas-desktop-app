<?php

namespace App\Http\Controllers;

use App\Exceptions\CentralApiAuthenticationException;
use App\Http\Requests\RegisterFamilyRequest;
use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\Evacuee;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\EvacueeRecord;
use App\Models\Family;
use App\Models\LocalAuth;
use App\Services\CentralApiService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class FamilyController extends Controller
{
    /**
     * Serves two audiences with one route: a direct browser visit (e.g. a
     * refresh while on this page) gets the full styled page, while the
     * "Register a family" buttons on the Dashboard and Registered Families
     * pages fetch this same route via JS and inject just the form as a
     * true in-page modal over whatever page they were on -- see
     * resources/js/app.js's openRegisterFamilyModal(). Both paths render
     * the exact same families._form partial with the exact same data, so
     * there is only one implementation to keep in sync, not two.
     */
    public function create(Request $request)
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }

        return $this->renderForm($request, $auth);
    }

    /**
     * Shared by create() and edit() -- identical form data either way, the
     * only difference is whether a $family is passed in for the view (and
     * therefore the JS) to pre-fill. See resources/js/app.js's
     * initRegisterFamilyForm() for how the presence of family data changes
     * behavior (starts from its existing members instead of one blank row,
     * pre-selects its current barangay/event/center/displacement type).
     */
    private function renderForm(Request $request, LocalAuth $auth, ?Family $family = null)
    {
        try {
            // Same local cache the "All Evacuees" page reads from --
            // reused here (not re-fetched) so the in-form duplicate warning
            // works fully offline, matching against whatever was cached as
            // of the last successful sync. The duplicate warning is a nice-
            // to-have, not core to registration -- if this cache table isn't
            // ready yet on this device (e.g. right after an app update),
            // registration must still work, just without that warning.
            $cachedEvacuees = EvacueeRecord::all(['head_name', 'barangay_name']);
        } catch (QueryException $e) {
            $cachedEvacuees = collect();
        }

        $data = [
            'currentUser' => $auth,
            'family' => $family,
            'barangays' => Barangay::orderBy('name')->get(),
            // Cached events only ever include non-closed ones -- see
            // EvacuationEventController::index() note on the central
            // server about client-side filtering for this exact reason.
            'events' => EvacuationEvent::where('status', '!=', 'closed')->orderByDesc('name')->get(),
            // Confirmed against the central server's actual enum
            // (app/Models/EvacuationCenter.php's migration on that side):
            // status is one of active|full|closed|on_standby, no soft
            // deletes -- a center is simply gone once decommissioned there.
            // Matches the same closed-exclusion pattern as events above,
            // so a decommissioned/closed center can no longer be selected
            // for a new registration even if a stale local copy briefly
            // lingers before the next reference-data refresh prunes it.
            'centers' => EvacuationCenter::where('status', '!=', 'closed')->get(['id', 'name', 'barangay_remote_id']),
            'cachedEvacuees' => $cachedEvacuees,
        ];

        if ($request->header('X-Modal-Request')) {
            return view('families._form', $data);
        }

        return view('families.create', $data);
    }

    /**
     * Saves entirely to the LOCAL SQLite database -- no internet required,
     * no API call made here at all. This is the whole point of the
     * offline companion: registration works the same whether or not the
     * device has a connection right now.
     */
    public function store(RegisterFamilyRequest $request)
    {
        $validated = $request->validated();

        $family = Family::create([
            'barangay_id' => $validated['barangay_id'],
            'home_address' => $validated['home_address'] ?? null,
            'evacuation_event_id' => $validated['evacuation_event_id'],
            'evacuation_center_id' => $validated['evacuation_center_id'] ?? null,
            'displacement_type' => $validated['displacement_type'],
            'is_4ps_beneficiary' => $validated['is_4ps_beneficiary'] ?? false,
        ]);

        $this->replaceMembers($family, $validated['members']);

        return redirect()->route('families.index')
            ->with('status', 'Family saved on this device. Sync when you have internet.');
    }

    /**
     * Shared by store() and update() -- (re)creates every evacuee row for a
     * family from a validated members array. On an existing family, wipes
     * its current members first rather than diffing/matching against the
     * submitted array: simpler and more robust than reconciling per-row
     * adds/edits/removals, and safe here because nothing downstream holds
     * a reference to an individual evacuee row's id (toSyncPayload()
     * re-reads the relationship fresh at sync time).
     */
    private function replaceMembers(Family $family, array $members): void
    {
        $family->evacuees()->delete();

        foreach ($members as $member) {
            Evacuee::create([
                'family_id' => $family->id,
                'first_name' => $member['first_name'],
                'middle_name' => $member['middle_name'] ?? null,
                'last_name' => $member['last_name'],
                'suffix' => $member['suffix'] ?? null,
                'sex' => $member['sex'],
                'date_of_birth' => $member['date_of_birth'],
                'civil_status' => $member['civil_status'] ?? null,
                'contact_number' => $member['contact_number'] ?? null,
                'is_pwd' => $member['is_pwd'] ?? false,
                'pwd_type' => $member['pwd_type'] ?? null,
                'is_pregnant' => $member['is_pregnant'] ?? false,
                'is_lactating' => $member['is_lactating'] ?? false,
                'is_solo_parent' => $member['is_solo_parent'] ?? false,
                'is_indigenous_person' => $member['is_indigenous_person'] ?? false,
                'is_4ps_beneficiary' => $member['is_4ps_beneficiary'] ?? false,
                'is_head_of_family' => $member['is_head_of_family'] ?? false,
            ]);
        }
    }

    /**
     * Barangay -> center -> family drill-down, matching the same
     * restructuring already done on the web dashboard's own families
     * page (there labeled "Evacuees") -- a flat list of every
     * registration on this device stops being scannable once there are
     * more than a handful. Global name search (see searchFamilies())
     * sits outside this drill-down entirely, exactly like the web
     * version's own "independent of the drill-down" search.
     *
     * One route, driven by query params (?search=, or ?barangay=&center=)
     * rather than separate named routes per level -- matches this
     * codebase's existing ?event= convention on the evacuation centers
     * pages, and keeps this a single controller action for one page.
     */
    public function index(Request $request)
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }

        // Shown as its own banner regardless of which drill-down level is
        // currently on screen -- a failed sync is exactly the kind of
        // thing that must stay visible without extra navigation, not
        // something that should only surface once someone happens to
        // drill down to the specific barangay+center it's in. The flat
        // list this page used to be showed every family's sync_error
        // directly; this replaces that visibility, not removes it.
        $sharedData = [
            'currentUser' => $auth,
            'syncErrors' => Family::whereNotNull('sync_error')->with(['barangay', 'evacuationCenter'])->get(),
        ];

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            return view('families.index', $sharedData + [
                'view' => 'search',
                'search' => $search,
                'families' => $this->searchFamilies($search),
            ]);
        }

        $barangayId = $request->query('barangay');
        if ($barangayId === null) {
            return view('families.index', $sharedData + [
                'view' => 'barangay',
                'barangaySummary' => $this->barangaySummary(),
                'ecBoardPendingByBarangay' => $this->ecBoardPendingCountsByBarangay(),
            ]);
        }

        $barangay = Barangay::find($barangayId);
        if (! $barangay) {
            // A stale link (e.g. this barangay was pruned from the cache
            // since) -- back to the top of the drill-down rather than a 404
            // in the middle of it.
            return redirect()->route('families.index');
        }

        $centerParam = $request->query('center');
        if ($centerParam === null) {
            return view('families.index', $sharedData + [
                'view' => 'center',
                'barangay' => $barangay,
                'centerSummary' => $this->centerSummary($barangay),
                'ecBoardPendingByCenter' => $this->ecBoardPendingCountsByCenter($barangay),
            ]);
        }

        $center = $centerParam !== 'none' ? EvacuationCenter::find($centerParam) : null;
        if ($centerParam !== 'none' && ! $center) {
            return redirect()->route('families.index', ['barangay' => $barangay->id]);
        }

        return view('families.index', $sharedData + [
            'view' => 'family',
            'barangay' => $barangay,
            'center' => $center,
            'centerParam' => $centerParam,
            'families' => $this->familiesForCenter($barangay, $centerParam),
        ]);
    }

    /**
     * One row per barangay this device has ANY registration for, family
     * counts included -- the landing view. Sorted by name via the
     * Collection (not the DB query) since the count comes from a raw
     * groupBy on the FOREIGN key, with no join to sort by the related
     * barangay's name at the SQL level.
     */
    private function barangaySummary()
    {
        return Family::selectRaw('barangay_id, count(*) as family_count')
            ->groupBy('barangay_id')
            ->with('barangay')
            ->get()
            ->sortBy(fn ($row) => $row->barangay->name ?? '')
            ->values();
    }

    /**
     * One row per evacuation center within $barangay, plus a trailing
     * "Outside center / unassigned" bucket for outside_center
     * registrations (evacuation_center_id is null for those -- see
     * RegisterFamilyRequest) -- same bucket the web dashboard's own
     * center-summary shows. Real centers are sorted by name; the
     * unassigned bucket (no name to sort by) always comes last.
     */
    private function centerSummary(Barangay $barangay)
    {
        $rows = Family::where('barangay_id', $barangay->id)
            ->selectRaw('evacuation_center_id, count(*) as family_count')
            ->groupBy('evacuation_center_id')
            ->with('evacuationCenter')
            ->get();

        $withCenter = $rows->filter(fn ($row) => $row->evacuation_center_id !== null)
            ->sortBy(fn ($row) => $row->evacuationCenter->name ?? '')
            ->values();

        $withoutCenter = $rows->filter(fn ($row) => $row->evacuation_center_id === null)->values();

        return $withCenter->concat($withoutCenter)->values();
    }

    /**
     * EC Board entries live in a completely separate table from Family
     * (a lighter-weight fast-tally headcount, not a full household
     * registration -- see EcBoardEntry's own migration comment), which
     * made a real pending entry architecturally invisible on this page: a
     * user could add one offline and never see it again here, only on the
     * EC Board page itself. Rather than folding EcBoardEntry rows INTO
     * this drill-down as fake "family" cards (they don't have most of a
     * family's own fields -- home_address, displacement_type, full member
     * details -- so faking that shape would be misleading), this surfaces
     * them as a clearly-labeled, clickable count alongside the real
     * family counts at each level -- visible without extra navigation,
     * with a direct path to where they're actually managed.
     *
     * Keyed by barangay remote_id (not local id) -- EcBoardEntry only
     * reaches a barangay indirectly, via its center's own
     * barangay_remote_id, which is a remote id throughout this cache
     * table (see evacuation_centers' own migration).
     */
    private function ecBoardPendingCountsByBarangay(): array
    {
        return EcBoardEntry::whereNull('synced_at')
            ->with('evacuationCenter')
            ->get()
            ->groupBy(fn (EcBoardEntry $e) => optional($e->evacuationCenter)->barangay_remote_id)
            ->map->count()
            ->all();
    }

    /**
     * Same as above, but keyed by this center's own LOCAL id (matching
     * $row->evacuation_center_id in centerSummary()'s own rows), since at
     * this level the badge links straight to one specific center's EC
     * Board page, not just an aggregate count.
     */
    private function ecBoardPendingCountsByCenter(Barangay $barangay): array
    {
        return EcBoardEntry::whereNull('synced_at')
            ->whereHas('evacuationCenter', fn ($q) => $q->where('barangay_remote_id', $barangay->remote_id))
            ->get()
            ->groupBy('evacuation_center_id')
            ->map->count()
            ->all();
    }

    /**
     * Level 3: the actual family list, scoped to one barangay+center --
     * the same content the old flat index() showed, just filtered now.
     * $centerParam is either a real center's local id, or the literal
     * string 'none' for the "Outside center / unassigned" bucket.
     */
    private function familiesForCenter(Barangay $barangay, string $centerParam)
    {
        return Family::where('barangay_id', $barangay->id)
            ->when($centerParam === 'none', fn ($q) => $q->whereNull('evacuation_center_id'))
            ->when($centerParam !== 'none', fn ($q) => $q->where('evacuation_center_id', $centerParam))
            ->with(['evacuees', 'barangay', 'evacuationEvent', 'evacuationCenter'])
            ->latest()
            ->get();
    }

    /**
     * Global search: finds a family by ANY member's name, regardless of
     * barangay or center -- independent of, and bypasses, the drill-down
     * above entirely, matching the web dashboard's own "family
     * reunification lookups never get slower because of the drill-down"
     * principle. Device-local data only ever belongs to whoever is
     * logged in here, so unlike the web version there's no further
     * access-scoping to apply on top of the name match itself.
     */
    private function searchFamilies(string $search)
    {
        return Family::whereHas('evacuees', function ($q) use ($search) {
            $q->where('first_name', 'like', "%{$search}%")
                ->orWhere('middle_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%");
        })
            ->with(['evacuees', 'barangay', 'evacuationEvent', 'evacuationCenter'])
            ->latest()
            ->get();
    }

    /**
     * Pushes every queued (not-yet-synced) family to the central server,
     * one at a time, using the same registration endpoint the web
     * dashboard uses. No separate sync protocol -- see Family::toSyncPayload().
     */
    public function sync(CentralApiService $api)
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }

        $pendingFamilies = Family::whereNull('synced_at')
            ->with(['evacuees', 'barangay', 'evacuationEvent', 'evacuationCenter'])
            ->get();

        $pendingEntries = EcBoardEntry::whereNull('synced_at')
            ->with(['evacuationEvent', 'evacuationCenter', 'household.evacuees'])
            ->get();

        if ($pendingFamilies->isEmpty() && $pendingEntries->isEmpty()) {
            return redirect()->route('families.index')
                ->with('status', 'Nothing to sync -- everything is already up to date.');
        }

        $successCount = 0;
        $failCount = 0;
        $centralUnreachable = false;

        foreach ($pendingFamilies as $family) {
            try {
                $remoteId = $api->registerFamily($auth->api_token, $family->toSyncPayload());
                $family->update(['remote_id' => $remoteId, 'synced_at' => now(), 'sync_error' => null]);
                $family->evacuees()->update(['synced_at' => now()]);
                $successCount++;
            } catch (CentralApiAuthenticationException $e) {
                // The token itself is dead -- not a problem with this
                // family's data, so it's left exactly as it was (still
                // "Waiting to sync", no sync_error stamped on it) rather
                // than being marked failed with a raw "Unauthenticated."
                // message that would wrongly imply something about ITS
                // data is wrong. Every other queued family would fail the
                // exact same way with the same dead token, so stop here
                // instead of repeating a doomed call for each one, and
                // surface a distinct, actionable message instead of the
                // generic sync summary below.
                return redirect()->route('families.index')->with(
                    'authExpired',
                    'Your session has expired. Please log in again to continue syncing.'
                );
            } catch (\RuntimeException $e) {
                $family->update(['sync_error' => $e->getMessage()]);
                $failCount++;

                // A "can't reach the server at all" failure means every
                // remaining queued family (and every queued EC Board entry
                // below) will fail the exact same way -- stop here instead
                // of repeating a doomed network call for each one and
                // showing the same error N times.
                if (str_contains($e->getMessage(), 'Could not reach the central server')) {
                    $centralUnreachable = true;
                    break;
                }
            }
        }

        // Pushed after families, in this same run, not a separate sync --
        // an "existing household" entry linked to a family queued above
        // needs that family's freshly-assigned remote_id, which only
        // exists once its own update() a few lines up has actually run.
        if (! $centralUnreachable) {
            foreach ($pendingEntries as $entry) {
                try {
                    $remoteId = $api->addEvacuee($auth->api_token, $entry->evacuationCenter->remote_id, $entry->toSyncPayload());
                    $entry->update(['remote_id' => $remoteId, 'synced_at' => now(), 'sync_error' => null]);
                    $successCount++;
                } catch (CentralApiAuthenticationException $e) {
                    return redirect()->route('families.index')->with(
                        'authExpired',
                        'Your session has expired. Please log in again to continue syncing.'
                    );
                } catch (\RuntimeException $e) {
                    $entry->update(['sync_error' => $e->getMessage()]);
                    $failCount++;

                    if (str_contains($e->getMessage(), 'Could not reach the central server')) {
                        break;
                    }
                }
            }
        }

        $message = "{$successCount} record(s) synced successfully.";
        if ($failCount > 0) {
            $message .= " {$failCount} failed -- see details below.";
        }

        return redirect()->route('families.index')->with('status', $message);
    }

    /**
     * Opens the exact same form used to register a family, pre-filled with
     * this one's current data, so a staff member can fix a single mistake
     * (a mistyped contact number, a since-deleted event) without deleting
     * and fully re-entering the whole registration. Scoped to not-yet-
     * synced families only -- once synced, the central server is the
     * source of truth for that record, and this device's local copy is
     * only ever a staging area on the way there, not a place to keep
     * editing it after the fact.
     */
    public function edit(Request $request, Family $family)
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }

        if ($family->isSynced()) {
            return redirect()->route('families.index')
                ->with('status', 'This family has already synced -- it can no longer be edited from this device.');
        }

        $family->load('evacuees');

        return $this->renderForm($request, $auth, $family);
    }

    public function update(RegisterFamilyRequest $request, Family $family)
    {
        if ($family->isSynced()) {
            return redirect()->route('families.index')
                ->with('status', 'This family has already synced -- it can no longer be edited from this device.');
        }

        $validated = $request->validated();

        $family->update([
            'barangay_id' => $validated['barangay_id'],
            'home_address' => $validated['home_address'] ?? null,
            'evacuation_event_id' => $validated['evacuation_event_id'],
            'evacuation_center_id' => $validated['evacuation_center_id'] ?? null,
            'displacement_type' => $validated['displacement_type'],
            'is_4ps_beneficiary' => $validated['is_4ps_beneficiary'] ?? false,
            // Clears out whatever validation error sent this record back
            // here in the first place -- it's about to get fresh data.
            'sync_error' => null,
        ]);

        $this->replaceMembers($family, $validated['members']);

        return redirect()->route('families.index')
            ->with('status', 'Family updated on this device. Sync when you have internet.');
    }

    /**
     * Removes a pending registration that can never sync as-is (data the
     * user has no way to correct into validity, or one they've decided not
     * to submit after all). Scoped to not-yet-synced families -- a synced
     * one already exists on the central server, so deleting the local copy
     * here wouldn't remove it there, it would just make this device's
     * history of what it submitted incomplete.
     */
    public function destroy(Family $family)
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }

        if ($family->isSynced()) {
            return redirect()->route('families.index')
                ->with('status', 'This family has already synced -- it can no longer be deleted from this device.');
        }

        $family->delete();

        return redirect()->route('families.index')
            ->with('status', 'Pending registration removed.');
    }
}
