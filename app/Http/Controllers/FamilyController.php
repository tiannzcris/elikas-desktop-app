<?php

namespace App\Http\Controllers;

use App\Exceptions\CentralApiAuthenticationException;
use App\Http\Requests\RegisterFamilyRequest;
use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\Evacuee;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\Family;
use App\Models\LocalAuth;
use App\Services\CentralApiService;
use Illuminate\Http\Request;

class FamilyController extends Controller
{
    /**
     * No page links here any more -- EC Board's Add Evacuee is the entry
     * path -- but the route stays reachable by direct URL for full,
     * detailed registrations. Serves two audiences with one route: a
     * direct visit gets the full styled page, while a data-modal-trigger
     * link (a pending card's Edit button) fetches this same form via JS
     * as an in-page modal -- see resources/js/app.js's
     * openRegisterFamilyModal(). Both render the same families._form
     * partial with the same data.
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
        // The in-form duplicate warning matches against this device's own
        // households -- the same records Registered families lists -- so it
        // works fully offline. The family being edited is left out so it
        // never warns about itself.
        $knownHouseholds = Family::with(['evacuees', 'barangay'])
            ->when($family, fn ($q) => $q->whereKeyNot($family->id))
            ->get()
            ->map(fn (Family $f) => [
                'head_name' => $f->evacuees->firstWhere('is_head_of_family', true)?->full_name ?? $f->name,
                'barangay_name' => $f->barangay->name ?? null,
            ])
            ->filter(fn (array $h) => $h['head_name'] !== null)
            ->values();

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
            'knownHouseholds' => $knownHouseholds,
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
     * The landing view is split in two: "Not yet synced" lists every
     * pending family on this device outright (it's the part staff act on,
     * and failed syncs belong in plain sight), and "Synced" is the
     * drill-down, which counts and lists synced families only. Both span
     * every barangay -- this is the device's own record, not a roster
     * scoped to the staff's barangay (that's the Evacuees page).
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
                'pendingByBarangay' => $this->pendingFamiliesByBarangay($auth),
                'ecBoardPendingByCenter' => $this->ecBoardPendingByCenter(),
                'barangaySummary' => $this->barangaySummary($auth),
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
     * One row per barangay this device has a SYNCED family for, family
     * counts included -- the landing view's "Synced" section. Sorted by name via the
     * Collection (not the DB query) since the count comes from a raw
     * groupBy on the FOREIGN key, with no join to sort by the related
     * barangay's name at the SQL level.
     *
     * The staff's own barangay (LocalAuth::barangay_id, a REMOTE id) is
     * always included and pinned first, even with zero registrations so
     * far -- otherwise a barangay with nothing registered yet would never
     * appear here at all, hiding the exact place staff are most likely to
     * start registering from.
     */
    private function barangaySummary(LocalAuth $auth)
    {
        $rows = Family::whereNotNull('synced_at')
            ->selectRaw('barangay_id, count(*) as family_count')
            ->groupBy('barangay_id')
            ->with('barangay')
            ->get();

        $ownBarangay = $auth->barangay_id ? Barangay::where('remote_id', $auth->barangay_id)->first() : null;

        if ($ownBarangay && ! $rows->contains('barangay_id', $ownBarangay->id)) {
            $rows->push((object) ['barangay_id' => $ownBarangay->id, 'family_count' => 0, 'barangay' => $ownBarangay]);
        }

        $sorted = $rows->sortBy(fn ($row) => $row->barangay->name ?? '')->values();

        if ($ownBarangay) {
            $ownRow = $sorted->firstWhere('barangay_id', $ownBarangay->id);
            $sorted = collect([$ownRow])->merge($sorted->reject(fn ($row) => $row->barangay_id === $ownBarangay->id))->values();
        }

        return $sorted;
    }

    /**
     * One row per evacuation center CURRENTLY HOSTING $barangay's
     * registered families, plus a trailing "Outside center / unassigned"
     * bucket for outside_center registrations (evacuation_center_id is
     * null for those -- see RegisterFamilyRequest) -- same bucket the
     * web dashboard's own center-summary shows. Real centers are sorted
     * by name; the unassigned bucket (no name to sort by) always comes
     * last.
     *
     * NOT "centers within $barangay" -- a family's registered barangay
     * (home) and the center it's actually sheltering at are independent:
     * nothing stops a Binatagan family from evacuating to a center that
     * physically belongs to Ranao-ranao (confirmed real, reproducible
     * case, not a hypothetical -- a family's own barangay_id has no
     * relationship to its evacuation_center_id's own barangay_remote_id
     * anywhere in this query). Each row below carries
     * locatedInDifferentBarangay (the OTHER barangay's name, or null when
     * the center's own barangay_remote_id actually matches $barangay) so
     * the view can flag that clearly instead of implying every center
     * listed here belongs to $barangay.
     */
    private function centerSummary(Barangay $barangay)
    {
        $rows = Family::where('barangay_id', $barangay->id)
            ->whereNotNull('synced_at')
            ->selectRaw('evacuation_center_id, count(*) as family_count')
            ->groupBy('evacuation_center_id')
            ->with('evacuationCenter.barangay')
            ->get();

        $rows->each(function ($row) use ($barangay) {
            $row->locatedInDifferentBarangay = $row->evacuationCenter
                && (int) $row->evacuationCenter->barangay_remote_id !== (int) $barangay->remote_id
                ? ($row->evacuationCenter->barangay->name ?? null)
                : null;
        });

        $withCenter = $rows->filter(fn ($row) => $row->evacuation_center_id !== null)
            ->sortBy(fn ($row) => $row->evacuationCenter->name ?? '')
            ->values();

        $withoutCenter = $rows->filter(fn ($row) => $row->evacuation_center_id === null)->values();

        return $withCenter->concat($withoutCenter)->values();
    }

    /**
     * The "Not yet synced" section's families, every barangay included,
     * grouped by barangay with the staff's own barangay first and the
     * rest alphabetical -- the same ordering as the Synced list below it.
     * Families created by EC Board's Add Evacuee are included: they sit
     * here until their originating entry syncs and stamps them.
     */
    private function pendingFamiliesByBarangay(LocalAuth $auth)
    {
        $grouped = Family::whereNull('synced_at')
            ->with(['evacuees', 'barangay', 'evacuationEvent', 'evacuationCenter', 'headEntry'])
            ->latest()
            ->get()
            ->toBase()
            ->groupBy(fn (Family $family) => $family->barangay->name ?? 'Unknown barangay')
            ->sortKeys();

        $ownName = $auth->barangay_id ? Barangay::where('remote_id', $auth->barangay_id)->value('name') : null;

        if ($ownName !== null && $grouped->has($ownName)) {
            $grouped = collect([$ownName => $grouped->get($ownName)])->merge($grouped->except($ownName));
        }

        return $grouped;
    }

    /**
     * People added on an EC Board that haven't synced yet, counted per
     * center. They're EcBoardEntry rows, not families -- someone added to
     * an existing household never becomes a Family card of their own --
     * so the "Not yet synced" section shows them as a count linking to
     * the center's EC Board, where they're managed, rather than faking
     * family cards for them.
     */
    private function ecBoardPendingByCenter()
    {
        return EcBoardEntry::whereNull('synced_at')
            ->with('evacuationCenter')
            ->get()
            ->groupBy('evacuation_center_id')
            ->map(fn ($entries) => (object) [
                'center' => $entries->first()->evacuationCenter,
                'count' => $entries->count(),
            ])
            ->filter(fn ($row) => $row->center !== null)
            ->sortBy(fn ($row) => $row->center->name)
            ->values();
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
            ->whereNotNull('synced_at')
            ->when($centerParam === 'none', fn ($q) => $q->whereNull('evacuation_center_id'))
            ->when($centerParam !== 'none', fn ($q) => $q->where('evacuation_center_id', $centerParam))
            ->with(['evacuees', 'barangay', 'evacuationEvent', 'evacuationCenter', 'headEntry'])
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
        return Family::where(function ($q) use ($search) {
            $q->whereHas('evacuees', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('middle_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            })
                // A household added through Add Evacuee whose head is someone
                // else has no head member row -- only its recorded name.
                ->orWhere('name', 'like', "%{$search}%");
        })
            ->with(['evacuees', 'barangay', 'evacuationEvent', 'evacuationCenter', 'headEntry'])
            ->latest()
            ->get();
    }

    /**
     * Pushes every queued (not-yet-synced) family to the central server,
     * one at a time, using the same registration endpoint the web
     * dashboard uses. No separate sync protocol -- see Family::toSyncPayload().
     *
     * Triggerable from more than one page now (Registered Families, and
     * the EC Board page itself -- see partials/_sync_button.blade.php),
     * so where this redirects back to afterward depends on where it was
     * triggered from: return_to_center_id/return_to_event_id, when
     * present, send the user back to that SAME EC Board page (refreshing
     * its own pending counts/breakdown), rather than always landing on
     * Registered Families. Resolved via resolveSyncRedirect() below,
     * built from an explicit route + a real, looked-up EvacuationCenter --
     * never a raw redirect URL taken from input, so there's no
     * open-redirect surface despite honoring caller-supplied ids.
     */
    public function sync(Request $request, CentralApiService $api)
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }

        $returnTo = $this->resolveSyncRedirect($request);

        // created_via_ec_board families are excluded here -- they never
        // go through registerFamily() at all (the real /families/register
        // endpoint requires date_of_birth/contact_number per member,
        // which "Add Evacuee" never collects). They sync instead through
        // their own originating ec_board_entries row below -- see
        // EcBoardEntry::toSyncPayload()'s originated_household docblock.
        $pendingFamilies = Family::whereNull('synced_at')
            ->where('created_via_ec_board', false)
            ->with(['evacuees', 'barangay', 'evacuationEvent', 'evacuationCenter'])
            ->get();

        // originated_household entries sorted first so a household
        // created AND given a second member in the SAME sync run
        // resolves in one pass: the second entry's toSyncPayload() needs
        // its Family already synced (the originating entry's own job, a
        // few lines below), which only happens if that one runs first.
        $pendingEntries = EcBoardEntry::whereNull('synced_at')
            ->orderByDesc('originated_household')
            ->with(['evacuationEvent', 'evacuationCenter', 'household.evacuees'])
            ->get();

        if ($pendingFamilies->isEmpty() && $pendingEntries->isEmpty()) {
            return redirect($returnTo)
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
                return redirect($returnTo)->with(
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
                    // Forces a fresh read of this entry's household --
                    // $pendingEntries eager-loaded it once, up front,
                    // before this loop started. Without this, a SECOND
                    // entry referencing the SAME household a prior
                    // iteration just synced (see the originated_household
                    // branch below) would still see that relation's
                    // stale, pre-sync state and wrongly fail toSyncPayload
                    // ()'s "has this household synced yet" check within
                    // this same run.
                    $entry->unsetRelation('household');

                    $result = $api->addEvacuee($auth->api_token, $entry->evacuationCenter->remote_id, $entry->toSyncPayload());
                    $entry->update(['remote_id' => $result['evacuee_id'], 'synced_at' => now(), 'sync_error' => null]);

                    // This entry's own submission created its linked
                    // Family locally (see EcBoardEntryController::
                    // createNewHousehold()) -- stamp the family id THIS
                    // same response just assigned it, since that Family
                    // has no other sync path of its own (created_via_ec_
                    // board is permanently excluded from the families
                    // loop above).
                    if ($entry->originated_household && $entry->household_family_local_id) {
                        Family::whereKey($entry->household_family_local_id)->update([
                            'remote_id' => $result['family_id'],
                            'synced_at' => now(),
                            'sync_error' => null,
                        ]);
                    }

                    $successCount++;
                } catch (CentralApiAuthenticationException $e) {
                    return redirect($returnTo)->with(
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

        return redirect($returnTo)->with('status', $message);
    }

    /**
     * Resolves where sync() should redirect back to -- Registered
     * Families by default, or the SAME EC Board page it was triggered
     * from when return_to_center_id is present (see this method's own
     * docblock on sync() for why). Deliberately builds a named route
     * from a real, looked-up EvacuationCenter rather than trusting any
     * raw URL from the request -- an invalid/missing center id falls
     * back to the safe default instead of erroring.
     */
    private function resolveSyncRedirect(Request $request): string
    {
        $centerId = $request->input('return_to_center_id');
        if (! $centerId) {
            return route('families.index');
        }

        $center = EvacuationCenter::find($centerId);
        if (! $center) {
            return route('families.index');
        }

        return route('evacuation-centers.ec-board', [
            'center' => $center,
            'event' => $request->input('return_to_event_id'),
        ]);
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

        if ($family->created_via_ec_board) {
            return $this->managedOnEcBoard();
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

        if ($family->created_via_ec_board) {
            return $this->managedOnEcBoard();
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

        if ($family->created_via_ec_board) {
            return $this->managedOnEcBoard();
        }

        $family->delete();

        return redirect()->route('families.index')
            ->with('status', 'Pending registration removed.');
    }

    /**
     * A household created by EC Board's Add Evacuee has no full
     * registration behind it (no birth dates, contact numbers or home
     * address), so the registration form can't edit it, and its entries
     * still point at it. It's changed or removed through its EC Board
     * entries instead, which clean it up themselves.
     */
    private function managedOnEcBoard()
    {
        return redirect()->route('families.index')
            ->with('status', 'This family was added on the EC Board -- edit or remove it from its EC Board entries.');
    }
}
