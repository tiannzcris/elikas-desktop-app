<?php

namespace App\Http\Controllers;

use App\Exceptions\CentralApiException;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Services\CentralApiService;
use App\Support\EventLock;
use Illuminate\Http\Request;

/**
 * The event's lifecycle as a barangay sees it: while CSWD monitors an
 * event, registering waits for "Start evacuation"; an official can tell
 * CSWD that families are already arriving (docs/backend/apps-api-2026-11-22.md 1).
 */
class EventLifecycleController extends Controller
{
    /** POST /evacuation-events/{id}/families-arriving {note?}: online only; CSWD is told. */
    public function familiesArriving(Request $request, CentralApiService $api)
    {
        $auth = LocalAuth::current();
        abort_unless($auth->isBarangayOfficial() && $auth->can('evacuees.manage'), 403);
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $event = EvacuationEvent::working();
        if (! $event?->isMonitoring()) {
            return back()->withErrors(['arriving' => $event?->isActive()
                ? 'Registration is already open: add the families on the EC Board.'
                : EventLock::reason($event, EventLock::REGISTERING)]);
        }

        try {
            $body = $api->familiesArriving($auth->api_token, $event->remote_id, $validated['note'] ?? null);
        } catch (CentralApiException $e) {
            return back()->withErrors(['arriving' => $e->isOffline() ? 'Needs a connection. CSWD was not told yet; try again when online.' : $e->getMessage()]);
        }

        return back()->with('status', ($body['message'] ?? '') ?: 'CSWD was told that families are arriving.');
    }
}
