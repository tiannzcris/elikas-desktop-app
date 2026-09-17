<?php

namespace App\Http\Controllers;

use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationCenterBreakdown;
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
     * Same closed-exclusion pattern as the family registration form's
     * center list (FamilyController::renderForm()) -- a decommissioned
     * center shouldn't be browsable for new entries either.
     */
    public function index()
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }

        $barangayNames = Barangay::pluck('name', 'remote_id');

        $centers = EvacuationCenter::where('status', '!=', 'closed')
            ->orderBy('name')
            ->get()
            ->map(fn (EvacuationCenter $c) => [
                'center' => $c,
                'barangayName' => $barangayNames[$c->barangay_remote_id] ?? 'Unknown barangay',
                'pendingCount' => EcBoardEntry::where('evacuation_center_id', $c->id)->whereNull('synced_at')->count(),
            ]);

        return view('evacuation-centers.index', [
            'currentUser' => $auth,
            'centers' => $centers,
        ]);
    }

    /**
     * Center detail: the dual-source breakdown (last-known-from-server vs
     * pending-on-this-device, kept deliberately separate -- see the
     * breakdownMatrix() helper) plus the Add Evacuee fast entry form and
     * this device's own not-yet-synced entries for this center.
     *
     * Scoped to one event at a time via ?event=, since both breakdown
     * sources and the add form are all per center+event -- defaults to the
     * most recently created cached event so there's always something
     * sensible selected on first visit.
     */
    public function show(EvacuationCenter $center, Request $request, CentralApiService $api)
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

        if ($selectedEventId) {
            $this->refreshLastKnownBreakdown($api, $auth, $center, $events->firstWhere('id', $selectedEventId));
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
        // THIS center, synced or not -- linking a new evacuee to a
        // household is meaningful regardless of whether that household's
        // own registration has reached the central server yet (that only
        // matters at sync time -- see EcBoardEntry::toSyncPayload()).
        $households = Family::where('evacuation_center_id', $center->id)
            ->with('evacuees')
            ->get();

        return view('evacuation-centers.show', [
            'currentUser' => $auth,
            'center' => $center,
            'barangayName' => Barangay::where('remote_id', $center->barangay_remote_id)->value('name') ?? 'Unknown barangay',
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
        ]);
    }

    /**
     * Refreshes this device's local "as of last sync" cache for ONE
     * center+event by calling the real central quick-count endpoint --
     * see CentralApiService::fetchCenterQuickCount()'s own docblock for
     * why this is scoped this way instead of a bulk refresh. Silently
     * falls back to whatever was cached from the last successful visit on
     * any failure (offline, session expired, endpoint down) -- same
     * online-first-else-cached pattern as EvacueeController::index(),
     * never blocks this page from rendering.
     */
    private function refreshLastKnownBreakdown(CentralApiService $api, LocalAuth $auth, EvacuationCenter $center, ?EvacuationEvent $event): void
    {
        if (! $event || ! $center->remote_id) {
            return;
        }

        try {
            $rows = $api->fetchCenterQuickCount($auth->api_token, $center->remote_id, $event->remote_id);
        } catch (\RuntimeException $e) {
            return;
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
