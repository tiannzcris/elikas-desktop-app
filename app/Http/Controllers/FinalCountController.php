<?php

namespace App\Http\Controllers;

use App\Models\LocalAuth;
use App\Models\ServerSnapshot;
use App\Services\CentralApiService;
use App\Services\CityViews;

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
