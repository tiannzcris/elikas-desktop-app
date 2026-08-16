<?php

namespace App\Http\Controllers;

use App\Models\EvacueeRecord;
use App\Models\LocalAuth;
use App\Services\CentralApiService;
use Illuminate\Http\Request;

class EvacueeController extends Controller
{
    /**
     * Tries a fresh pull from the central server first and updates the
     * local cache when that succeeds. Falls back to whatever was cached
     * from the last successful pull when offline, or when the server
     * rejects the request -- the page always has something to show; it
     * never hard-fails just because there's no internet right now.
     *
     * Accepts an optional ?q= filter so the registration form's inline
     * duplicate warning can link straight to the matched name instead of
     * dumping staff into the full, unfiltered roster.
     */
    public function index(CentralApiService $api, Request $request)
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
        $search = trim((string) $request->query('q', ''));

        // Grouped by barangay for CSWD/admin accounts whose roster spans
        // every barangay -- a barangay official's cache only ever contains
        // their own barangay (per existing access scoping), so this is a
        // no-op single group for that role, not a special case to handle.
        // SQL already sorts both levels, so groupBy() just has to preserve
        // that order, which Collection::groupBy() does.
        $evacuees = EvacueeRecord::query()
            ->when($search !== '', fn ($q) => $q->where('head_name', 'like', "%{$search}%"))
            ->orderBy('barangay_name')
            ->orderBy('head_name')
            ->get()
            ->groupBy(fn (EvacueeRecord $record) => $record->barangay_name ?: 'Unknown barangay');

        return view('evacuees.index', [
            'currentUser' => $auth,
            'evacueesByBarangay' => $evacuees,
            'search' => $search,
            'lastSyncedAt' => $latest?->updated_at,
            'isStale' => $isStale,
        ]);
    }
}
