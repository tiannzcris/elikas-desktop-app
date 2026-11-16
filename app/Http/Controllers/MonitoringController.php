<?php

namespace App\Http\Controllers;

use App\Models\LocalAuth;
use App\Models\ServerSnapshot;
use App\Services\CentralApiService;
use App\Services\CityViews;

/** CSWD Monitoring, read-only, from the copy kept on this device. */
class MonitoringController extends Controller
{
    public function index(CentralApiService $api)
    {
        $sections = collect(CityViews::MONITORING_SECTIONS)->mapWithKeys(fn ($s) => [$s => ServerSnapshot::named("monitoring.{$s}")]);
        $overview = $sections['overview'];

        return view('monitoring.index', [
            'currentUser' => LocalAuth::current(),
            'sections' => $sections->map(fn ($s) => $s?->payload['data'] ?? null),
            'event' => $overview?->payload['evacuation_event'] ?? null,
            'generatedAt' => $overview?->payload['generated_at'] ?? null,
            'hasCopy' => $overview !== null,
            'webUrl' => $api->webUrl(),
        ]);
    }

    /** Fetches the pages again (needs internet). */
    public function refresh(CityViews $views)
    {
        try {
            $views->refresh(LocalAuth::current());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['refresh' => $e->getMessage()]);
        }

        return back()->with('status', 'Brought up to date.');
    }
}
