<?php

namespace App\Http\Controllers;

use App\Exceptions\CentralApiException;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\ServerSnapshot;
use App\Services\CentralApiService;
use App\Services\CityViews;
use Illuminate\Http\Request;

/**
 * CSWD Final counts, read-only: each barangay's submitted count for the
 * current event and where it stands. Validating or returning is on the web.
 */
class FinalCountController extends Controller
{
    /** Order on the page: waiting first, then returned, not yet, validated. */
    private const ORDER = ['pending' => 0, 'returned' => 1, 'none' => 2, 'validated' => 3];

    public function index(CentralApiService $api)
    {
        $snapshot = ServerSnapshot::named('final_counts');
        $rows = collect($snapshot?->payload['barangays'] ?? [])
            ->map(fn (array $row) => $row + ['state' => $row['current_submission']['status'] ?? 'none'])
            ->sortBy(fn (array $row) => [self::ORDER[$row['state']] ?? 9, $row['barangay']['name'] ?? ''])
            ->values();

        return view('final-counts.index', [
            'currentUser' => LocalAuth::current(),
            'snapshot' => $snapshot,
            'event' => $snapshot?->payload['evacuation_event'] ?? null,
            'rows' => $rows,
            'counts' => $rows->countBy('state'),
            'webUrl' => $api->webUrl(),
        ]);
    }

    /**
     * CSWD submits the count for a barangay with no official account
     * (POST /barangays/{id}/count-submissions): the system's count at that
     * moment, labelled "Submitted by CSWD for the barangay". Online only.
     */
    public function submitOnBehalf(Request $request, CentralApiService $api, CityViews $views)
    {
        $auth = LocalAuth::current();
        abort_unless($auth->can('count_submissions.submit_on_behalf'), 403);
        $validated = $request->validate([
            'barangay_id' => ['required', 'integer'],
            'barangay_name' => ['required', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $event = EvacuationEvent::working();
        if (! $event || $event->status !== 'active') {
            return back()->withErrors(['submit' => 'A final count can be submitted only for an active event.']);
        }

        try {
            $api->send('post', "barangays/{$validated['barangay_id']}/count-submissions", $auth->api_token, array_filter([
                'evacuation_event_id' => $event->remote_id,
                'note' => $validated['note'] ?? null,
            ]));
        } catch (CentralApiException $e) {
            return back()->withErrors(['submit' => $e->isOffline() ? 'Needs a connection. Nothing was submitted.' : $e->getMessage()]);
        }

        try {
            $views->refreshFinalCounts($auth);
        } catch (\RuntimeException $e) {
            // Submitted; the list catches up at the next refresh.
        }

        return back()->with('status', "Final count submitted for {$validated['barangay_name']} (submitted by CSWD for the barangay).");
    }

    public function refresh(CityViews $views)
    {
        try {
            $views->refreshFinalCounts(LocalAuth::current());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['refresh' => $e->getMessage()]);
        }

        return back()->with('status', 'Brought up to date.');
    }
}
