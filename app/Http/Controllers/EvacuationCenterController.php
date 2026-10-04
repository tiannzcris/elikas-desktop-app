<?php

namespace App\Http\Controllers;

use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationCenterBreakdown;
use App\Models\EvacuationCenterSectoralSnapshot;
use App\Models\EvacuationEvent;
use App\Models\Family;
use App\Models\LocalAuth;
use App\Services\CentralApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

        // Always include the staff's own barangay, even if it happens to
        // have zero cached centers right now -- pinned first below either
        // way, so it's never simply missing from the list they'd expect
        // to see it in first.
        if ($auth->barangay_id) {
            $barangayRemoteIds->push($auth->barangay_id);
            $barangayRemoteIds = $barangayRemoteIds->unique();
        }

        $rows = Barangay::whereIn('remote_id', $barangayRemoteIds)
            ->orderBy('name')
            ->get()
            ->map(function (Barangay $barangay) use ($auth) {
                $centerIds = EvacuationCenter::where('barangay_remote_id', $barangay->remote_id)
                    ->where('status', '!=', 'closed')
                    ->pluck('id');

                return [
                    'barangay' => $barangay,
                    'centerCount' => $centerIds->count(),
                    'pendingCount' => EcBoardEntry::whereIn('evacuation_center_id', $centerIds)->whereNull('synced_at')->count(),
                    'isOwnBarangay' => $auth->barangay_id !== null && $barangay->remote_id === $auth->barangay_id,
                ];
            });

        // Pinned first -- see this method's own docblock/Part 2 fix for
        // why, everything else keeps its existing alphabetical order.
        $ownRow = $rows->firstWhere('isOwnBarangay', true);
        if ($ownRow) {
            $rows = collect([$ownRow])->merge($rows->reject(fn ($row) => $row['isOwnBarangay']))->values();
        }

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

        // A closed event stays on this board only while entries added here
        // for it are still waiting on this device -- they can no longer
        // sync (the server refuses new evacuees there), so they must stay
        // reachable to be seen and removed.
        $events = EvacuationEvent::where('status', '!=', 'closed')
            ->orWhereIn('id', EcBoardEntry::where('evacuation_center_id', $center->id)->whereNull('synced_at')->select('evacuation_event_id'))
            ->orderByDesc('id')
            ->get();

        $selectedEventId = (int) $request->query('event', 0);
        if (! $events->contains('id', $selectedEventId)) {
            $selectedEventId = optional($events->first(fn (EvacuationEvent $e) => ! $e->isClosed()) ?? $events->first())->id;
        }

        $pendingEntries = $this->pendingEntriesQuery($center, $selectedEventId)
            ->with(['evacuationEvent', 'household.evacuees'])
            ->latest()
            ->get();

        // Existing-household picker: households already associated with
        // THIS center and event in this device's OWN local cache, synced
        // or not --
        // linking a new evacuee to a household is meaningful regardless of
        // whether that household's own registration has reached the
        // central server yet (that only matters at sync time -- see
        // EcBoardEntry::toSyncPayload()). Households known to the central
        // server but never locally cached (e.g. registered elsewhere) are
        // appended to this same list client-side, on demand, while online
        // -- see refreshHouseholds() below -- not fetched here, for the
        // same blocking-request reason breakdown refresh moved client-side.
        $households = Family::where('evacuation_center_id', $center->id)
            ->where('evacuation_event_id', $selectedEventId)
            ->with('evacuees')
            ->get();

        // Derived straight from the center's own barangay_remote_id, not
        // carried through the URL as a query param -- this always resolves
        // to the SAME barangay regardless of which page linked here (the
        // new EC Board flow, a bookmark, or a direct URL), so "Back"
        // always lands somewhere correct rather than trusting stale state.
        // Null only if that barangay somehow isn't cached locally.
        $backBarangay = Barangay::where('remote_id', $center->barangay_remote_id)->first();

        return view('evacuation-centers.ec-board', $this->boardData($center, $selectedEventId) + [
            'currentUser' => $auth,
            'center' => $center,
            'barangayName' => Barangay::where('remote_id', $center->barangay_remote_id)->value('name') ?? 'Unknown barangay',
            'backBarangay' => $backBarangay,
            'events' => $events,
            'selectedEventId' => $selectedEventId,
            'selectedEventClosed' => (bool) $events->firstWhere('id', $selectedEventId)?->isClosed(),
            'pendingEntries' => $pendingEntries,
            'households' => $households,
            // New family's "Home barangay" choices.
            'barangays' => Barangay::orderBy('name')->get(),
            // The Add Evacuee form's own picker -- the 7 real brackets
            // only, distinct from the board's breakdownAgeBrackets.
            'ageBrackets' => EcBoardEntry::AGE_BRACKETS,
        ]);
    }

    private function pendingEntriesQuery(EvacuationCenter $center, ?int $eventId)
    {
        return EcBoardEntry::where('evacuation_center_id', $center->id)
            ->whereNull('synced_at')
            ->when($eventId, fn ($q) => $q->where('evacuation_event_id', $eventId));
    }

    /**
     * Everything the board sheet shows (evacuation-centers._board_figures),
     * for one center+event: the central server's figures as last fetched
     * (or nothing yet, if never refreshed while online) beside this
     * device's own not-yet-synced contribution. The two are shown side by
     * side on each row and never summed -- reconciling them only means
     * something once everything has reached the central server. Shared by
     * the page and both refresh endpoints, so a refresh re-renders the
     * whole sheet from whatever is cached by then.
     */
    private function boardData(EvacuationCenter $center, ?int $eventId): array
    {
        $breakdownBrackets = array_merge(array_keys(EcBoardEntry::AGE_BRACKETS), [self::UNCLASSIFIED_BRACKET]);

        $lastKnownBreakdown = $this->breakdownMatrix(
            $eventId
                ? EvacuationCenterBreakdown::where('evacuation_center_id', $center->id)
                    ->where('evacuation_event_id', $eventId)
                    ->get()
                    ->map(fn (EvacuationCenterBreakdown $row) => ['age_bracket' => $row->age_bracket, 'sex' => $row->sex, 'count' => $row->count])
                : collect(),
            $breakdownBrackets
        );

        $pendingEntries = $this->pendingEntriesQuery($center, $eventId)->get();

        // Pending entries are always one of the 7 real, selectable
        // brackets -- never 'unclassified', that bracket only ever comes
        // from the server's own live computation -- but seeded with the
        // same bracket list so both sides fill the same rows.
        $pendingBreakdown = $this->breakdownMatrix(
            $pendingEntries->map(fn (EcBoardEntry $e) => ['age_bracket' => $e->age_bracket, 'sex' => $e->sex, 'count' => 1]),
            $breakdownBrackets
        );

        return [
            'lastKnownBreakdown' => $lastKnownBreakdown,
            'pendingBreakdown' => $pendingBreakdown,
            'breakdownAgeBrackets' => EcBoardEntry::AGE_BRACKETS + [self::UNCLASSIFIED_BRACKET => 'Unclassified (missing details)'],
            'sectoralSnapshot' => $eventId
                ? EvacuationCenterSectoralSnapshot::where('evacuation_center_id', $center->id)
                    ->where('evacuation_event_id', $eventId)
                    ->first()
                : null,
            'sectoralGroups' => EvacuationCenterSectoralSnapshot::SECTORAL_GROUPS,
            'pendingSectoral' => $this->pendingSectoralBreakdown($pendingEntries),
            'pendingCount' => $pendingEntries->count(),
        ];
    }

    /**
     * This device's not-yet-synced contribution to the sectoral table, by
     * the central server's own rule (EvacuationCenterQuickCount::
     * liveSectoralBreakdown() there), in its row order: the six per-person
     * groups from each pending entry's own flags, by that person's sex, and
     * Child-/Single-Headed Family once per household this device created,
     * by the head's sex. null answers and unknown sexes count nowhere.
     *
     * @return \Illuminate\Support\Collection<string, array{label: string, male: int, female: int}> keyed by sectoral group
     */
    private function pendingSectoralBreakdown(Collection $pendingEntries): Collection
    {
        $households = Family::whereIn('id', $pendingEntries->where('originated_household', true)->pluck('household_family_local_id'))
            ->with('headEntry')
            ->get();

        $flagByGroup = collect(EcBoardEntry::SECTORAL_FLAGS)->mapWithKeys(fn ($meta, $flag) => [$meta[1] => $flag]);

        return collect(EvacuationCenterSectoralSnapshot::SECTORAL_GROUPS)->map(function ($label, $group) use ($households, $pendingEntries, $flagByGroup) {
            if ($method = EvacuationCenterSectoralSnapshot::HOUSEHOLD_SECTORAL_GROUPS[$group] ?? null) {
                $counted = $households->filter(fn (Family $f) => $f->{$method}() === true);
                $sexOf = fn (Family $f) => $f->headSex();
            } else {
                $flag = $flagByGroup[$group];
                $counted = $pendingEntries->filter(fn (EcBoardEntry $e) => $e->{$flag} === true);
                $sexOf = fn (EcBoardEntry $e) => $e->sex;
            }

            return [
                'label' => $label,
                'male' => $counted->filter(fn ($x) => $sexOf($x) === 'male')->count(),
                'female' => $counted->filter(fn ($x) => $sexOf($x) === 'female')->count(),
            ];
        });
    }

    /**
     * Called client-side (fetch(), after the page itself has rendered) to
     * refresh the whole board for one center+event from the central
     * server's quick-count -- see ecBoard()'s own docblock for why this is
     * not part of that synchronous page render. One response carries the
     * header figures, age/sex rows, 4Ps and sectoral groups, so all of it
     * is cached together, stamped with the same fetched_at -- the board's
     * "As of", which offline keeps showing when this last succeeded.
     * Silently returns nothing useful on any failure (offline, session
     * expired, endpoint down); the calling JS leaves the board as it is.
     * Returns the board figures partial the page itself renders, so there
     * is only one implementation of that markup.
     */
    public function refreshBoard(EvacuationCenter $center, Request $request, CentralApiService $api)
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
            $data = $api->fetchCenterQuickCount($auth->api_token, $center->remote_id, $event->remote_id);
        } catch (\RuntimeException $e) {
            return response()->noContent(503);
        }

        DB::transaction(function () use ($center, $event, $data) {
            EvacuationCenterBreakdown::where('evacuation_center_id', $center->id)
                ->where('evacuation_event_id', $event->id)
                ->delete();

            // Each row carries both male_count and female_count (even the
            // trailing 'unclassified' row -- see fetchCenterQuickCount()'s
            // docblock) -- stored here as two rows, matching this table's
            // own one-row-per-(bracket,sex) shape. The 'unclassified' row's
            // own extra total_count (covering anyone missing BOTH sex and
            // bracket) has nowhere to go in that shape and is deliberately
            // not stored -- a rare edge case, not worth widening this
            // schema for.
            foreach ($data['age_groups'] ?? [] as $row) {
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

            EvacuationCenterSectoralSnapshot::updateOrCreate(
                ['evacuation_center_id' => $center->id, 'evacuation_event_id' => $event->id],
                [
                    'families_cumulative' => $data['families_cumulative'] ?? null,
                    'families_now' => $data['families_now'] ?? null,
                    'persons_cumulative' => $data['persons_cumulative'] ?? null,
                    'persons_now' => $data['persons_now'] ?? null,
                    'beneficiaries_4ps' => $data['beneficiaries_4ps'] ?? 0,
                    'sectoral_groups' => $data['sectoral_groups'] ?? [],
                    'updated_by_name' => $data['updated_by_name'] ?? null,
                    'server_updated_at' => $data['updated_at'] ?? null,
                    'fetched_at' => now(),
                ]
            );
        });

        return view('evacuation-centers._board_figures', $this->boardData($center, $event->id));
    }

    /**
     * Called client-side (fetch(), after the page itself has rendered) to
     * pull the families here right now -- someone in them still checked in
     * at this center for this event -- straight from the central server
     * (see CentralApiService::fetchFamiliesAtCenter()'s own docblock).
     * Returns JSON:
     *
     * - households: those NOT already in this device's own local list
     *   (deduped by remote id, since a synced local household would
     *   otherwise show up twice), each as {value, label, head_linked}
     *   ready for the Add Evacuee form's household <select> -- value is
     *   "remote-{id}" (see AddEvacueeRequest's own note on that format).
     *   Another barangay's family comes from the server without a name, and
     *   is labelled as on the web dashboard: "Family #N · <barangay> · X
     *   here".
     * - here_remote_ids: every family the server says is here, so the form
     *   can drop this device's SYNCED households that have since left (the
     *   server would refuse them). Households not yet synced stay: they
     *   were added here on this device and the server hasn't seen them.
     *
     * Empty/error responses are exactly as safe as no response at all: the
     * form already has its own local households list to fall back to,
     * offline or not.
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
            ->map(fn ($f) => empty($f['is_generic'])
                ? [
                    'value' => 'remote-'.$f['id'],
                    'label' => $f['name'] ?? ($f['head_of_family']['full_name'] ?? null) ?? 'Family #'.$f['id'],
                    // Drives whether Add Evacuee offers "This person is the
                    // family head" for it -- the server's head_of_family is
                    // null until a head is linked (verified live).
                    'head_linked' => ! empty($f['head_of_family']),
                ]
                : [
                    'value' => 'remote-'.$f['id'],
                    'label' => 'Family #'.$f['id'].' · '.($f['barangay']['name'] ?? 'Unknown barangay').' · '.($f['here_count'] ?? 0).' here',
                    // No head_of_family for another barangay's family: the
                    // server says whether one is linked instead.
                    'head_linked' => ! empty($f['has_head_linked']),
                ])
            ->values();

        return response()->json([
            'households' => $households,
            'here_remote_ids' => collect($remoteFamilies)->pluck('id')->values(),
        ]);
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
