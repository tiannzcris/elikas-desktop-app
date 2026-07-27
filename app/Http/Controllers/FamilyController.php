<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterFamilyRequest;
use App\Models\Barangay;
use App\Models\Evacuee;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\Family;
use App\Models\LocalAuth;
use App\Services\CentralApiService;

class FamilyController extends Controller
{
    public function create()
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }

        return view('families.create', [
            'currentUser' => $auth,
            'barangays' => Barangay::orderBy('name')->get(),
            // Cached events only ever include non-closed ones -- see
            // EvacuationEventController::index() note on the central
            // server about client-side filtering for this exact reason.
            'events' => EvacuationEvent::where('status', '!=', 'closed')->orderByDesc('name')->get(),
            'centers' => EvacuationCenter::all(['id', 'name', 'barangay_remote_id']),
        ]);
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
