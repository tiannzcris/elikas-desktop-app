<?php

namespace App\Http\Controllers;

use App\Exceptions\CentralApiException;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Services\BarangayFinalCount;
use App\Services\LocalData;
use App\Support\EventLock;
use Illuminate\Http\Request;

/**
 * The barangay official's Final count: the system's count now, late
 * entries, the system checks, and Submit (online only; waits while the
 * event is monitored). A count CSWD submitted for the barangay says so.
 */
class BarangayFinalCountController extends Controller
{
    public function __construct(private BarangayFinalCount $finalCount) {}

    public function index(LocalData $local)
    {
        $auth = LocalAuth::current();
        $event = EvacuationEvent::working();

        return view('final-count.index', [
            'currentUser' => $auth,
            'event' => $event,
            'count' => $this->finalCount->forEvent($event),
            'lock' => EventLock::reason($event, EventLock::FINAL_COUNTS),
            'unsent' => $local->pendingCount(),
            'maySubmit' => $auth->can('count_submissions.submit'),
        ]);
    }

    public function refresh()
    {
        $event = EvacuationEvent::working();
        if ($event) {
            try {
                $this->finalCount->refresh(LocalAuth::current(), $event);
            } catch (CentralApiException $e) {
                return back()->withErrors(['refresh' => $e->isOffline() ? 'Needs a connection. Showing the count as of its last refresh.' : $e->getMessage()]);
            }
        }

        return back()->with('status', 'Brought up to date.');
    }

    public function submit(Request $request)
    {
        $auth = LocalAuth::current();
        abort_unless($auth->can('count_submissions.submit'), 403);
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $event = EvacuationEvent::working();
        EventLock::guard($event, EventLock::FINAL_COUNTS);

        try {
            $body = $this->finalCount->submit($auth, $event, $validated['note'] ?? null);
        } catch (CentralApiException $e) {
            return back()->withInput()->withErrors(['submit' => $e->isOffline() ? 'Needs a connection. Nothing was submitted.' : $e->getMessage()]);
        }

        try {
            $this->finalCount->refresh($auth, $event);
        } catch (CentralApiException $e) {
            // Submitted; the page catches up at the next refresh.
        }

        return back()->with('status', ($body['message'] ?? '') ?: 'Final count submitted. CSWD validates it; new evacuees from now on are late entries.');
    }
}
