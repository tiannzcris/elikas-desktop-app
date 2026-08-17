<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterFamilyRequest;
use App\Models\Barangay;
use App\Models\Evacuee;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\EvacueeRecord;
use App\Models\Family;
use App\Models\LocalAuth;
use App\Services\CentralApiService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class FamilyController extends Controller
{
    /**
     * Serves two audiences with one route: a direct browser visit (e.g. a
     * refresh while on this page) gets the full styled page, while the
     * "Register a family" buttons on the Dashboard and Registered Families
     * pages fetch this same route via JS and inject just the form as a
     * true in-page modal over whatever page they were on -- see
     * resources/js/app.js's openRegisterFamilyModal(). Both paths render
     * the exact same families._form partial with the exact same data, so
     * there is only one implementation to keep in sync, not two.
     */
    public function create(Request $request)
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }

        try {
            // Same local cache the "All Evacuees" page reads from --
            // reused here (not re-fetched) so the in-form duplicate warning
            // works fully offline, matching against whatever was cached as
            // of the last successful sync. The duplicate warning is a nice-
            // to-have, not core to registration -- if this cache table isn't
            // ready yet on this device (e.g. right after an app update),
            // registration must still work, just without that warning.
            $cachedEvacuees = EvacueeRecord::all(['head_name', 'barangay_name']);
        } catch (QueryException $e) {
            $cachedEvacuees = collect();
        }

        $data = [
            'currentUser' => $auth,
            'barangays' => Barangay::orderBy('name')->get(),
            // Cached events only ever include non-closed ones -- see
            // EvacuationEventController::index() note on the central
            // server about client-side filtering for this exact reason.
            'events' => EvacuationEvent::where('status', '!=', 'closed')->orderByDesc('name')->get(),
            'centers' => EvacuationCenter::all(['id', 'name', 'barangay_remote_id']),
            'cachedEvacuees' => $cachedEvacuees,
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

        foreach ($validated['members'] as $member) {
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

        return redirect()->route('families.index')
            ->with('status', 'Family saved on this device. Sync when you have internet.');
    }

    public function index()
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }

        return view('families.index', [
            'currentUser' => $auth,
            'families' => Family::with(['evacuees', 'barangay', 'evacuationEvent'])->latest()->get(),
        ]);
    }

    /**
     * Pushes every queued (not-yet-synced) family to the central server,
     * one at a time, using the same registration endpoint the web
     * dashboard uses. No separate sync protocol -- see Family::toSyncPayload().
     */
    public function sync(CentralApiService $api)
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }

        $pending = Family::whereNull('synced_at')
            ->with(['evacuees', 'barangay', 'evacuationEvent', 'evacuationCenter'])
            ->get();

        if ($pending->isEmpty()) {
            return redirect()->route('families.index')
                ->with('status', 'Nothing to sync -- everything is already up to date.');
        }

        $successCount = 0;
        $failCount = 0;

        foreach ($pending as $family) {
            try {
                $remoteId = $api->registerFamily($auth->api_token, $family->toSyncPayload());
                $family->update(['remote_id' => $remoteId, 'synced_at' => now(), 'sync_error' => null]);
                $family->evacuees()->update(['synced_at' => now()]);
                $successCount++;
            } catch (\RuntimeException $e) {
                $family->update(['sync_error' => $e->getMessage()]);
                $failCount++;

                // A "can't reach the server at all" failure means every
                // remaining queued family will fail the exact same way --
                // stop here instead of repeating a doomed network call for
                // each one and showing the same error N times.
                if (str_contains($e->getMessage(), 'Could not reach the central server')) {
                    break;
                }
            }
        }

        $message = "{$successCount} record(s) synced successfully.";
        if ($failCount > 0) {
            $message .= " {$failCount} failed -- see details below.";
        }

        return redirect()->route('families.index')->with('status', $message);
    }
}
