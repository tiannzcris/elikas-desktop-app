<?php

namespace App\Http\Controllers;

use App\Exceptions\CentralApiException;
use App\Models\Barangay;
use App\Models\CenterFamilySnapshot;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Services\CentralApiService;
use App\Services\Transfers;
use App\Support\EventLock;
use App\Support\Plural;
use Illuminate\Http\Request;

/**
 * Moving families when a center is full (server: transfer requests and the
 * direct move). Every write here is online only -- never queued -- and is
 * confirmed first (docs/backend/apps-api-2026-11-transfers-capacity.md).
 */
class TransferController extends Controller
{
    private const OFFLINE = 'Needs a connection. Nothing was sent.';

    // ------------------------------------------------------------ the list

    public function index(Transfers $transfers)
    {
        $auth = LocalAuth::current();
        $event = EvacuationEvent::working();

        return view('transfers.index', [
            'currentUser' => $auth,
            'event' => $event,
            'list' => $transfers->forEvent($event),
        ]);
    }

    /** Reloads the list (also when a transfer notification is opened). */
    public function refresh(Transfers $transfers)
    {
        try {
            $transfers->refresh(LocalAuth::current());
        } catch (CentralApiException $e) {
            return redirect()->route('transfers.index')->withErrors(['refresh' => $e->isOffline() ? 'Needs a connection. Showing the list as of its last refresh.' : $e->getMessage()]);
        }

        return redirect()->route('transfers.index');
    }

    public function decide(Request $request, int $requestId, CentralApiService $api, Transfers $transfers)
    {
        if ($lock = EventLock::reason(EvacuationEvent::working(), EventLock::TRANSFERS)) {
            return redirect()->route('transfers.index')->withErrors(['decide' => $lock]);
        }
        $accept = $request->input('decision') === 'accept';
        $validated = $request->validate([
            'decision' => ['required', 'in:accept,decline'],
            'reason' => $accept ? ['nullable'] : ['required', 'string', 'min:3', 'max:500'],
        ], ['reason.required' => 'Say why the request is declined.']);

        try {
            $message = $api->decideTransfer(LocalAuth::current()->api_token, $requestId, $accept, $validated['reason'] ?? null);
        } catch (CentralApiException $e) {
            return back()->withErrors(['decide' => $e->isOffline() ? self::OFFLINE : $e->getMessage()]);
        }
        $this->reload($transfers);

        return redirect()->route('transfers.index')->with('status', $message ?: ($accept ? 'Accepted.' : 'Declined.'));
    }

    // ------------------------------------------- an official moves families

    /** Ticked families of one center: move to another of our centers, or ask another barangay. */
    public function moveForm(Request $request, EvacuationCenter $center, CentralApiService $api)
    {
        $auth = LocalAuth::current();
        $event = EvacuationEvent::working();
        if ($lock = EventLock::reason($event, EventLock::TRANSFERS)) {
            return redirect()->route('evacuation-centers.ec-board', $center)->withErrors(['move' => $lock]);
        }
        $families = $this->ticked($center, $event, (array) $request->query('families', []));
        $persons = (int) collect($families)->sum('here');

        // Live: room changes by the minute, so a copy is never used.
        $suggestions = null;
        $suggestionError = null;
        if ($families && $auth->can('transfers.request')) {
            try {
                $suggestions = $api->transferSuggestions($auth->api_token, $center->remote_id, $persons)['centers'] ?? [];
            } catch (CentralApiException $e) {
                $suggestionError = $e->isOffline() ? 'Needs a connection to find centers with room.' : $e->getMessage();
            }
        }

        return view('transfers.move', [
            'currentUser' => $auth,
            'center' => $center,
            'event' => $event,
            'families' => $families,
            'persons' => $persons,
            'ownCenters' => EvacuationCenter::where('barangay_remote_id', $auth->barangay_id)->where('id', '!=', $center->id)
                ->where('status', '!=', 'closed')->where('approval_status', '!=', 'rejected')->orderBy('name')->get(),
            'suggestions' => $suggestions,
            'suggestionError' => $suggestionError,
        ]);
    }

    /** "Move to another of our centers": a direct move, no approval. */
    public function moveDirect(Request $request, EvacuationCenter $center, CentralApiService $api)
    {
        $auth = LocalAuth::current();
        $event = EvacuationEvent::working();
        EventLock::guard($event, EventLock::TRANSFERS);
        $validated = $request->validate([
            'families' => ['required', 'array', 'min:1'],
            'to_center_id' => ['required', 'integer', 'exists:evacuation_centers,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $target = EvacuationCenter::findOrFail($validated['to_center_id']);
        abort_unless((int) $target->barangay_remote_id === (int) $auth->barangay_id, 403);
        $families = $this->ticked($center, $event, $validated['families']);

        $moved = 0;
        foreach ($families as $family) {
            try {
                $api->moveFamily($auth->api_token, $family['remote_id'], $target->remote_id, $validated['reason'] ?? null);
                $moved++;
            } catch (CentralApiException $e) {
                $this->refreshCenters([$center, $target], $event, $auth, $api);

                return back()->withErrors(['move' => ($e->isOffline() ? self::OFFLINE : $e->getMessage())
                    .($moved ? ' '.Plural::of($moved, 'family was', 'families were').' moved before this.' : '')]);
            }
        }
        $this->refreshCenters([$center, $target], $event, $auth, $api);

        return redirect()->route('evacuation-centers.ec-board', $center)
            ->with('status', Plural::of($moved, 'family', 'families')." moved to {$target->name}.");
    }

    /** "Ask another barangay's center": a request the other barangay accepts or declines. */
    public function requestTransfer(Request $request, EvacuationCenter $center, CentralApiService $api, Transfers $transfers)
    {
        $auth = LocalAuth::current();
        $event = EvacuationEvent::working();
        EventLock::guard($event, EventLock::TRANSFERS);
        $validated = $request->validate([
            'families' => ['required', 'array', 'min:1', 'max:100'],
            'to_center_remote_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['to_center_remote_id.required' => 'Choose the center to move them to.']);
        $families = $this->ticked($center, $event, $validated['families']);

        try {
            $row = $api->createTransferRequest($auth->api_token, array_filter([
                'evacuation_event_id' => $event->remote_id,
                'family_ids' => array_column($families, 'remote_id'),
                'to_center_id' => (int) $validated['to_center_remote_id'],
                'note' => $validated['note'] ?? null,
            ], fn ($v) => $v !== null));
        } catch (CentralApiException $e) {
            // No client_ref here: after a lost answer, the list says whether it went through.
            $this->reload($transfers);

            return back()->withInput()->withErrors(['request' => $e->isOffline() ? self::OFFLINE.' Check the Transfers list before sending again.' : $e->getMessage()]);
        }
        $this->reload($transfers);

        return redirect()->route('transfers.index')->with('status', 'Transfer request sent to '.($row['to_center']['name'] ?? 'the center').'. The families stay at their center until it is accepted.');
    }

    // ------------------------------------------- CSWD moves a family directly

    public function cityMoveForm(Request $request, CentralApiService $api)
    {
        $auth = LocalAuth::current();
        $event = EvacuationEvent::working();
        if ($lock = EventLock::reason($event, EventLock::TRANSFERS)) {
            return redirect()->route('transfers.index')->withErrors(['move' => $lock]);
        }
        $from = $request->filled('from') ? EvacuationCenter::find($request->integer('from')) : null;

        $families = null;
        $error = null;
        if ($from && $event) {
            try {
                $families = CenterFamilySnapshot::fetch($api, $auth->api_token, $from, $event)->families;
            } catch (CentralApiException $e) {
                $error = $e->isOffline() ? 'Needs a connection to list the families at this center.' : $e->getMessage();
            }
        }

        return view('transfers.city-move', [
            'currentUser' => $auth,
            'event' => $event,
            'centers' => EvacuationCenter::where('status', '!=', 'closed')->where('approval_status', '!=', 'rejected')->orderBy('name')->get(),
            'barangays' => Barangay::pluck('name', 'remote_id'),
            'from' => $from,
            'families' => $families,
            'error' => $error,
        ]);
    }

    public function cityMove(Request $request, CentralApiService $api)
    {
        $auth = LocalAuth::current();
        abort_unless($auth->isCityWide() && $auth->can('evacuees.transfer'), 403);
        EventLock::guard(EvacuationEvent::working(), EventLock::TRANSFERS);
        $validated = $request->validate([
            'from_center_id' => ['required', 'integer', 'exists:evacuation_centers,id'],
            'family_remote_id' => ['required', 'integer'],
            'family_label' => ['nullable', 'string', 'max:200'],
            'to_center_id' => ['required', 'integer', 'exists:evacuation_centers,id', 'different:from_center_id'],
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'Say why the family is being transferred.',
            'to_center_id.different' => 'The family is already at that center.',
        ]);
        $target = EvacuationCenter::findOrFail($validated['to_center_id']);

        try {
            $api->moveFamily($auth->api_token, (int) $validated['family_remote_id'], $target->remote_id, $validated['reason']);
        } catch (CentralApiException $e) {
            return back()->withInput()->withErrors(['move' => $e->isOffline() ? self::OFFLINE : $e->getMessage()]);
        }

        return redirect()->route('transfers.city-move', ['from' => $validated['from_center_id']])
            ->with('status', ($validated['family_label'] ?: 'The family')." moved to {$target->name}.");
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The ticked families, as the server last listed them at this center.
     * Families added on this device and not yet sent have no server id, so
     * they can't be moved until they are sent.
     *
     * @return list<array{remote_id: int, label: string, here: int}>
     */
    private function ticked(EvacuationCenter $center, ?EvacuationEvent $event, array $keys): array
    {
        if (! $event) {
            return [];
        }
        $wanted = collect($keys)->map(fn ($k) => (int) str_replace('remote-', '', (string) $k))->filter()->all();

        return CheckOutController::familiesHere($center, $event)
            ->filter(fn ($f) => $f['remote_id'] && in_array($f['remote_id'], $wanted, true))
            ->map(fn ($f) => ['remote_id' => $f['remote_id'], 'label' => $f['label'], 'here' => (int) ($f['here'] ?? 0)])
            ->values()->all();
    }

    private function reload(Transfers $transfers): void
    {
        try {
            $transfers->refresh(LocalAuth::current());
        } catch (CentralApiException $e) {
            // The list catches up at the next refresh.
        }
    }

    /** After a move, both centers' lists are read again (best effort). */
    private function refreshCenters(array $centers, ?EvacuationEvent $event, LocalAuth $auth, CentralApiService $api): void
    {
        foreach ($centers as $c) {
            try {
                if ($event) {
                    CenterFamilySnapshot::fetch($api, $auth->api_token, $c, $event);
                }
            } catch (CentralApiException $e) {
                // Kept as it was.
            }
        }
    }
}
