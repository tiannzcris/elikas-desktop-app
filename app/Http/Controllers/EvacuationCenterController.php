<?php

namespace App\Http\Controllers;

use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationCenterBreakdown;
use App\Models\EvacuationCenterQuickCount;
use App\Models\EvacuationEvent;
use App\Models\Family;
use App\Models\LocalAuth;
use App\Services\CentralApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class EvacuationCenterController extends Controller
{
    /**
     * A trailing bucket the real central endpoint reports alongside its 7
     * real age brackets (see EvacuationCenterQuickCount::liveAgeSexBreakdown()
     * on the backend), for anyone currently checked in who is missing
     * sex/age_bracket entirely. Never selectable when adding an evacuee
     * here (that's EcBoardEntry::AGE_BRACKETS, the 7 real picks only) --
     * this exists purely so the "As of last sync" table has a row to show
     * that data in when the server reports it, instead of silently
     * dropping it.
     */
    private const UNCLASSIFIED_BRACKET = 'unclassified';

    /**
     * The sidebar's "EC Board" landing page -- step 1 of the real
     * barangay -> centers -> board flow (replacing the earlier stopgap
     * that just relabeled the old Evacuation Centers management list).
     * Only barangays with at least one non-closed center are listed --
     * an empty barangay has nowhere for this flow to go next, same
     * closed-exclusion reasoning as index()/ecBoard() below. This route
     * is now what the sidebar's "EC Board" link points to; the OLD
     * management list below (index()/show()) still exists at its own
     * URL, reachable via a secondary link on this page -- see this
     * method's view for where.
     */
    public function ecBoardBarangays()
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }

        $barangayRemoteIds = EvacuationCenter::where('status', '!=', 'closed')
            ->pluck('barangay_remote_id')
            ->unique();

        $rows = Barangay::whereIn('remote_id', $barangayRemoteIds)
            ->orderBy('name')
            ->get()
            ->map(function (Barangay $barangay) {
                $centerIds = EvacuationCenter::where('barangay_remote_id', $barangay->remote_id)
                    ->where('status', '!=', 'closed')
                    ->pluck('id');

                return [
                    'barangay' => $barangay,
                    'centerCount' => $centerIds->count(),
                    'pendingCount' => EcBoardEntry::whereIn('evacuation_center_id', $centerIds)->whereNull('synced_at')->count(),
                ];
            });

        return view('ec-board.index', [
            'currentUser' => $auth,
            'rows' => $rows,
        ]);
    }

    /**
     * Step 2 of the EC Board flow: this one barangay's own centers, name
     * only -- pure fast navigation, no occupancy/capacity/facilities here
     * (those still live on the old management pages, linked from here).
     */
    public function ecBoardCenters(Barangay $barangay)
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }

        $centers = EvacuationCenter::where('barangay_remote_id', $barangay->remote_id)
            ->where('status', '!=', 'closed')
            ->orderBy('name')
            ->get()
            ->map(fn (EvacuationCenter $c) => [
                'center' => $c,
                'pendingCount' => EcBoardEntry::where('evacuation_center_id', $c->id)->whereNull('synced_at')->count(),
            ]);

        return view('ec-board.centers', [
            'currentUser' => $auth,
            'barangay' => $barangay,
            'centers' => $centers,
        ]);
    }

    /**
     * Grouped by barangay -- simpler than the full barangay -> center ->
     * detail drill-down Registered Families uses, since this list never
     * needs to go past barangay -> centers -> one center's own detail
     * page (which already exists). Same closed-exclusion pattern as the
     * family registration form's center list (FamilyController::
     * renderForm()) -- a decommissioned center shouldn't be browsable for
     * new entries either.
     *
     * This is now the OLD center-management entry point (create/edit
     * centers' basic info) -- "EC Board" in the sidebar points at
     * ecBoardBarangays() above instead, per Cristian's 4-item sidebar
     * preference (Dashboard, EC Board, Registered Families, All
     * Evacuees). Still fully reachable via a secondary link from the new
     * EC Board pages, just no longer a top-level nav item itself.
     */
    public function index()
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }

        $barangayNames = Barangay::pluck('name', 'remote_id');

        $centersByBarangay = EvacuationCenter::where('status', '!=', 'closed')
            ->orderBy('name')
            ->get()
            ->map(fn (EvacuationCenter $c) => [
                'center' => $c,
                'barangayName' => $barangayNames[$c->barangay_remote_id] ?? 'Unknown barangay',
                'pendingCount' => EcBoardEntry::where('evacuation_center_id', $c->id)->whereNull('synced_at')->count(),
            ])
            ->groupBy('barangayName')
            ->sortKeys();

        return view('evacuation-centers.index', [
            'currentUser' => $auth,
            'centersByBarangay' => $centersByBarangay,
        ]);
    }

    /**
     * Basic/static center info ONLY -- name, barangay, status, plus a
     * prominent link to the EC Information Board (see ecBoard() below).
     * Split out from what used to be one combined page, matching the same
     * split just done on the web dashboard (elikas-backend's own
     * evacuation-centers/show.blade.php + ec-board.blade.php): a staff
     * member arriving here to check a center's basic details shouldn't
     * have to load past a live headcount/Add-Evacuee form to get to them,
     * and vice versa.
     *
     * NOTE: unlike the web dashboard's own basic-info page, this device
     * has no local cache of address/facilities/camp-manager/capacity at
     * all -- fetchReferenceData()/the evacuation_centers table only ever
     * stored name/status/barangay_remote_id (confirmed against
     * AuthController::refreshReferenceData() and the evacuation_centers
     * migration). Showing those fields here would mean extending what
     * reference data this device caches, which is separate, larger scope
     * from splitting this existing page -- flagged here rather than
     * silently omitted.
     */
    public function show(EvacuationCenter $center)
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }

        return view('evacuation-centers.show', [
            'currentUser' => $auth,
            'center' => $center,
            'barangayName' => Barangay::where('remote_id', $center->barangay_remote_id)->value('name') ?? 'Unknown barangay',
            'pendingCount' => EcBoardEntry::where('evacuation_center_id', $center->id)->whereNull('synced_at')->count(),
        ]);
    }

    /**
     * The EC Information Board: the dual-source breakdown (last-known-
     * from-server vs pending-on-this-device, kept deliberately separate --
     * see the breakdownMatrix() helper) plus the Add Evacuee fast entry
     * form and this device's own not-yet-synced entries for this center.
     * Everything EC-Board-related lives here now, not on show() above.
     *
     * Scoped to one event at a time via ?event=, since both breakdown
     * sources and the add form are all per center+event -- defaults to the
     * most recently created cached event so there's always something
     * sensible selected on first visit.
     *
     * Deliberately does NOT make any live network call itself -- an
     * earlier version called refreshLastKnownBreakdown() synchronously
     * here, which blocks NativePHP's local PHP server (a single-request-
     * at-a-time `php artisan serve` process) for the duration of that
     * call. While offline, that call hangs until it times out, and for
     * that whole window the SAME browser tab's own concurrent requests
     * for this page's CSS/JS/font assets queue up behind it and fail --
     * a real, reproduced bug: the EC Board page rendered with correct
     * data but no styling at all, specifically offline, while every other
     * page (none of which make a live call during their own render)
     * worked fine. The live refresh now happens client-side, via fetch(),
     * AFTER the page (and its assets) have already loaded -- see
     * refreshBreakdown() below and the script block in ec-board.blade.php.
     */
    public function ecBoard(EvacuationCenter $center, Request $request)
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }

        $events = EvacuationEvent::where('status', '!=', 'closed')->orderByDesc('id')->get();

        $selectedEventId = (int) $request->query('event', 0);
        if (! $events->contains('id', $selectedEventId)) {
            $selectedEventId = optional($events->first())->id;
        }

        $breakdownBrackets = array_merge(array_keys(EcBoardEntry::AGE_BRACKETS), [self::UNCLASSIFIED_BRACKET]);

        $lastKnownBreakdown = $selectedEventId
            ? $this->breakdownMatrix(
                EvacuationCenterBreakdown::where('evacuation_center_id', $center->id)
                    ->where('evacuation_event_id', $selectedEventId)
                    ->get()
                    ->map(fn (EvacuationCenterBreakdown $row) => ['age_bracket' => $row->age_bracket, 'sex' => $row->sex, 'count' => $row->count]),
                $breakdownBrackets
            )
            : $this->breakdownMatrix(collect(), $breakdownBrackets);

        $pendingEntriesQuery = EcBoardEntry::where('evacuation_center_id', $center->id)
            ->whereNull('synced_at')
            ->when($selectedEventId, fn ($q) => $q->where('evacuation_event_id', $selectedEventId));

        // Pending entries are always one of the 7 real, selectable
        // brackets -- never 'unclassified', that bracket only ever comes
        // from the server's own live computation -- but seeded with the
        // same $breakdownBrackets list as the last-known matrix above so
        // both tables render an identical set of rows for a clean visual
        // comparison.
        $pendingBreakdown = $this->breakdownMatrix(
            (clone $pendingEntriesQuery)->get()->map(fn (EcBoardEntry $e) => ['age_bracket' => $e->age_bracket, 'sex' => $e->sex, 'count' => 1]),
            $breakdownBrackets
        );

        $pendingEntries = $pendingEntriesQuery->with(['evacuationEvent', 'household.evacuees'])->latest()->get();

        // Existing-household picker: households already associated with
        // THIS center in this device's OWN local cache, synced or not --
        // linking a new evacuee to a household is meaningful regardless of
        // whether that household's own registration has reached the
        // central server yet (that only matters at sync time -- see
        // EcBoardEntry::toSyncPayload()). Households known to the central
        // server but never locally cached (e.g. registered elsewhere) are
        // appended to this same list client-side, on demand, while online
        // -- see refreshHouseholds() below -- not fetched here, for the
        // same blocking-request reason breakdown refresh moved client-side.
        $households = Family::where('evacuation_center_id', $center->id)
            ->with('evacuees')
            ->get();

        // Derived straight from the center's own barangay_remote_id, not
        // carried through the URL as a query param -- this always resolves
        // to the SAME barangay regardless of which page linked here (the
        // new EC Board flow, a bookmark, or a direct URL), so "Back"
        // always lands somewhere correct rather than trusting stale state.
        // Null only if that barangay somehow isn't cached locally.
        $backBarangay = Barangay::where('remote_id', $center->barangay_remote_id)->first();

        $quickCount = $selectedEventId
            ? EvacuationCenterQuickCount::with('sectoralGroups')
                ->where('evacuation_center_id', $center->id)
                ->where('evacuation_event_id', $selectedEventId)
                ->first()
            : null;

        return view('evacuation-centers.ec-board', [
            'currentUser' => $auth,
            'center' => $center,
            'barangayName' => Barangay::where('remote_id', $center->barangay_remote_id)->value('name') ?? 'Unknown barangay',
            'backBarangay' => $backBarangay,
            'events' => $events,
            'selectedEventId' => $selectedEventId,
            'lastKnownBreakdown' => $lastKnownBreakdown,
            'pendingBreakdown' => $pendingBreakdown,
            'pendingEntries' => $pendingEntries,
            'households' => $households,
            // The Add Evacuee form's own picker -- the 7 real brackets
            // only, distinct from $breakdownAgeBrackets below.
            'ageBrackets' => EcBoardEntry::AGE_BRACKETS,
            'breakdownAgeBrackets' => EcBoardEntry::AGE_BRACKETS + [self::UNCLASSIFIED_BRACKET => 'Unclassified (missing details)'],
            'quickCount' => $quickCount,
            'sectoralGroups' => EvacuationCenterQuickCount::SECTORAL_GROUPS,
        ]);
    }

    /**
     * Saves this device's locally-reported sectoral/4Ps figures for one
     * center+event -- confirmed missing from this app entirely until now
     * (see EvacuationCenterQuickCount's own docblock). Always saves
     * entirely to the LOCAL database first, same as EcBoardEntryController
     * ::store() -- no live API call from this request, synced_at stays
     * null (pending) until the next manual "Sync now". Overwrites the
     * SAME row on every save (not a growing queue): there is only ever
     * one current figure to report per center+event, matching how the
     * web dashboard's own "Save beneficiaries & sectoral figures" form
     * always submits the full table.
     */
    public function saveSectoral(EvacuationCenter $center, Request $request)
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }

        $validated = $request->validate([
            'evacuation_event_id' => ['required', 'integer', 'exists:evacuation_events,id'],
            'beneficiaries_4ps' => ['required', 'integer', 'min:0'],
            'sectoral_groups' => ['array'],
            'sectoral_groups.*.sectoral_group' => ['required', 'in:'.implode(',', array_keys(EvacuationCenterQuickCount::SECTORAL_GROUPS))],
            'sectoral_groups.*.male_count' => ['required', 'integer', 'min:0'],
            'sectoral_groups.*.female_count' => ['required', 'integer', 'min:0'],
        ]);

        $quickCount = EvacuationCenterQuickCount::updateOrCreate(
            ['evacuation_center_id' => $center->id, 'evacuation_event_id' => $validated['evacuation_event_id']],
            [
                'beneficiaries_4ps' => $validated['beneficiaries_4ps'],
                // Changed just now -- needs pushing again, same reasoning
                // as FamilyController::update() clearing sync_error on edit.
                'synced_at' => null,
                'sync_error' => null,
            ]
        );

        foreach ($validated['sectoral_groups'] ?? [] as $group) {
            $quickCount->sectoralGroups()->updateOrCreate(
                ['sectoral_group' => $group['sectoral_group']],
                ['male_count' => $group['male_count'], 'female_count' => $group['female_count']]
            );
        }

        return redirect()->route('evacuation-centers.ec-board', ['center' => $center, 'event' => $validated['evacuation_event_id']])
            ->with('status', 'Sectoral figures saved on this device. Sync when you have internet.');
    }

    /**
     * Called client-side (fetch(), after the page itself has rendered) to
     * refresh the "As of last sync" breakdown for one center+event -- see
     * ecBoard()'s own docblock for why this is no longer part of that
     * synchronous page render. Silently returns nothing useful on any
     * failure (offline, session expired, endpoint down); the calling JS
     * just leaves whatever was already on screen untouched in that case.
     * Returns the same breakdown-table partial the page itself renders,
     * so there is only one implementation of that table's markup.
     */
    public function refreshBreakdown(EvacuationCenter $center, Request $request, CentralApiService $api)
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return response()->noContent(401);
        }

        $eventId = (int) $request->query('event', 0);
        $event = $eventId ? EvacuationEvent::find($eventId) : null;

        if (! $event || ! $center->remote_id) {
            return response()->noContent(422);
        }

        try {
            // fetchCenterQuickCount() returns the full quick-count payload
            // (age_groups, sectoral_groups, beneficiaries_4ps) -- only
            // age_groups is relevant here, since this endpoint refreshes
            // just the age/sex breakdown table.
            $rows = $api->fetchCenterQuickCount($auth->api_token, $center->remote_id, $event->remote_id)['age_groups'] ?? [];
        } catch (\RuntimeException $e) {
            return response()->noContent(503);
        }

        EvacuationCenterBreakdown::where('evacuation_center_id', $center->id)
            ->where('evacuation_event_id', $event->id)
            ->delete();

        // Each row carries both male_count and female_count (even the
        // trailing 'unclassified' row -- see fetchCenterQuickCount()'s
        // docblock) -- stored here as two rows, matching this table's own
        // one-row-per-(bracket,sex) shape. The 'unclassified' row's own
        // extra total_count (covering anyone missing BOTH sex and
        // bracket) has nowhere to go in that shape and is deliberately not
        // stored -- a rare edge case, not worth widening this schema for.
        foreach ($rows as $row) {
            foreach (['male' => 'male_count', 'female' => 'female_count'] as $sex => $countKey) {
                EvacuationCenterBreakdown::create([
                    'evacuation_center_id' => $center->id,
                    'evacuation_event_id' => $event->id,
                    'sex' => $sex,
                    'age_bracket' => $row['age_bracket'],
                    'count' => $row[$countKey] ?? 0,
                ]);
            }
        }

        $breakdownBrackets = array_merge(array_keys(EcBoardEntry::AGE_BRACKETS), [self::UNCLASSIFIED_BRACKET]);

        $matrix = $this->breakdownMatrix(
            EvacuationCenterBreakdown::where('evacuation_center_id', $center->id)
                ->where('evacuation_event_id', $event->id)
                ->get()
                ->map(fn (EvacuationCenterBreakdown $row) => ['age_bracket' => $row->age_bracket, 'sex' => $row->sex, 'count' => $row->count]),
            $breakdownBrackets
        );

        return view('evacuation-centers._breakdown_table', [
            'matrix' => $matrix,
            'ageBrackets' => EcBoardEntry::AGE_BRACKETS + [self::UNCLASSIFIED_BRACKET => 'Unclassified (missing details)'],
        ]);
    }

    /**
     * Called client-side (fetch(), after the page itself has rendered) to
     * pull households already registered at this center+event straight
     * from the central server -- see CentralApiService::
     * fetchFamiliesAtCenter()'s own docblock. Returns JSON: a list of
     * households NOT already in this device's own local list (deduped by
     * remote id, since a synced local household would otherwise show up
     * twice), each as {value, label} ready for the Add Evacuee form's
     * household <select> -- value is "remote-{id}" (see
     * AddEvacueeRequest's own note on that format). Empty/error responses
     * are exactly as safe as no response at all: the form already has its
     * own local households list to fall back to, offline or not.
     */
    public function refreshHouseholds(EvacuationCenter $center, Request $request, CentralApiService $api)
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return response()->json([], 401);
        }

        $eventId = (int) $request->query('event', 0);
        $event = $eventId ? EvacuationEvent::find($eventId) : null;

        if (! $event || ! $center->remote_id) {
            return response()->json([], 422);
        }

        try {
            $remoteFamilies = $api->fetchFamiliesAtCenter($auth->api_token, $center->remote_id, $event->remote_id);
        } catch (\RuntimeException $e) {
            return response()->json([], 503);
        }

        $knownRemoteIds = Family::where('evacuation_center_id', $center->id)
            ->whereNotNull('remote_id')
            ->pluck('remote_id')
            ->all();

        $households = collect($remoteFamilies)
            ->reject(fn ($f) => in_array($f['id'], $knownRemoteIds, true))
            ->map(fn ($f) => [
                'value' => 'remote-'.$f['id'],
                'label' => $f['name'] ?? ($f['head_of_family']['full_name'] ?? null) ?? 'Household #'.$f['id'],
            ])
            ->values();

        return response()->json($households);
    }

    /**
     * Turns a flat list of {age_bracket, sex, count} rows into a
     * [bracket => ['male' => n, 'female' => n, 'total' => n], ..., 'total' => [...]]
     * matrix -- shared shape for both breakdown sources so the view can
     * render them with one identical partial and never conflate the two.
     */
    private function breakdownMatrix(Collection $rows, array $bracketKeys): array
    {
        $matrix = [];
        foreach ($bracketKeys as $bracket) {
            $matrix[$bracket] = ['male' => 0, 'female' => 0, 'total' => 0];
        }
        $matrix['total'] = ['male' => 0, 'female' => 0, 'total' => 0];

        foreach ($rows as $row) {
            if (! isset($matrix[$row['age_bracket']])) {
                continue;
            }
            $matrix[$row['age_bracket']][$row['sex']] += $row['count'];
            $matrix[$row['age_bracket']]['total'] += $row['count'];
            $matrix['total'][$row['sex']] += $row['count'];
            $matrix['total']['total'] += $row['count'];
        }

        return $matrix;
    }
}
