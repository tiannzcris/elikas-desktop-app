<?php

namespace App\Http\Controllers;

use App\Models\AppState;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\LocalAuth;
use App\Models\ServerSnapshot;
use App\Services\CityViews;
use App\Services\SyncService;

/**
 * The start page, answer first (web design system rule 2): what decides
 * the next step. Officials: what waits to be sent and their centers.
 * CSWD: the headline figures of the current event and what waits for
 * validation, from the copies kept on this device.
 */
class DashboardController extends Controller
{
    public function index(SyncService $sync, CityViews $views)
    {
        $auth = LocalAuth::current();

        if ($auth->isCityWide()) {
            $overview = ServerSnapshot::named('monitoring.overview');
            $finalCounts = ServerSnapshot::named('final_counts');

            return view('dashboard-city', [
                'currentUser' => $auth,
                'event' => $overview?->payload['evacuation_event'] ?? null,
                'figures' => $overview?->payload['data']['figures'] ?? null,
                'generatedAt' => $overview?->payload['generated_at'] ?? null,
                'waitingValidation' => collect($finalCounts?->payload['barangays'] ?? [])
                    ->where('current_submission.status', 'pending')->count(),
                'notSubmitted' => collect($finalCounts?->payload['barangays'] ?? [])
                    ->whereNull('current_submission')->count(),
                'waitingForCswd' => count(ServerSnapshot::named('monitoring.requests')?->payload['data']['requests'] ?? []),
                // GET /dashboard/city actions.transfer_requests_pending, else the Transfers list.
                'transfersPending' => (int) (ServerSnapshot::named('city_dashboard')?->payload['actions']['transfer_requests_pending']
                    ?? app(\App\Services\Transfers::class)->waitingCount($auth, \App\Models\EvacuationEvent::working())),
                'capacityCounts' => ServerSnapshot::named('city_dashboard')?->payload['capacity'] ?? null,
            ]);
        }

        $centers = EvacuationCenter::where('barangay_remote_id', $auth->barangay_id)
            ->where('status', '!=', 'closed')->orderBy('name')->get()
            ->map(fn (EvacuationCenter $c) => [
                'center' => $c,
                'pending' => EcBoardEntry::where('evacuation_center_id', $c->id)->whereNull('synced_at')->count(),
            ]);

        return view('dashboard', [
            'currentUser' => $auth,
            'event' => $views->currentEvent(),
            'waiting' => $sync->waiting()->count(),
            'attention' => $sync->needsAttention()->count(),
            'lastSyncAt' => AppState::get('last_sync_at'),
            'centers' => $centers,
            'board' => app(\App\Services\BarangayDashboard::class)->forEvent($views->currentEvent()),
            'webUrl' => app(\App\Services\CentralApiService::class)->webUrl(),
        ]);
    }
}
