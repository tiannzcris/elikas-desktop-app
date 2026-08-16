<?php

namespace App\Http\Controllers;

use App\Models\EvacueeRecord;
use App\Models\LocalAuth;
use App\Services\CentralApiService;

class EvacueeController extends Controller
{
    /**
     * Tries a fresh pull from the central server first and updates the
     * local cache when that succeeds. Falls back to whatever was cached
     * from the last successful pull when offline, or when the server
     * rejects the request -- the page always has something to show; it
     * never hard-fails just because there's no internet right now.
     */
    public function index(CentralApiService $api)
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }

        $isStale = true;

        try {
            foreach ($api->fetchEvacuees($auth->api_token) as $record) {
                EvacueeRecord::updateOrCreate(['remote_id' => $record['id']], [
                    'head_name' => $record['head_name'] ?? null,
                    'barangay_name' => $record['barangay_name'] ?? null,
                    'member_count' => $record['member_count'] ?? 0,
                ]);
            }

            $isStale = false;
        } catch (\RuntimeException $e) {
            // No internet, or the server rejected the request -- fall
            // through to whatever's already cached below, no error shown.
            // This is an expected, normal state for an offline companion
            // app, not a failure.
        }

        $latest = EvacueeRecord::latest('updated_at')->first();

        return view('evacuees.index', [
            'currentUser' => $auth,
            'evacuees' => EvacueeRecord::orderBy('barangay_name')->orderBy('head_name')->get(),
            'lastSyncedAt' => $latest?->updated_at,
            'isStale' => $isStale,
        ]);
    }
}
