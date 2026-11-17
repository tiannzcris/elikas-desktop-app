<?php

namespace App\Http\Controllers;

use App\Exceptions\CentralApiException;
use App\Models\AppState;
use App\Models\EvacuationCenter;
use App\Models\LocalAuth;
use App\Models\PendingReEnrollment;
use App\Services\CentralApiService;
use App\Services\SyncService;
use App\Support\Plural;
use Illuminate\Http\Request;

/**
 * The Sync page: what this device still has to send, what the server
 * refused, and the one-at-a-time sender the page drives (step()).
 */
class SyncController extends Controller
{
    public function index(SyncService $sync)
    {
        $label = fn (array $item) => [
            'key' => $item['key'],
            'kind' => $item['kind'],
            'model' => $item['model'],
            'label' => $sync->label($item),
            'place' => $sync->place($item),
            'blockedBy' => $sync->blockedBy($item),
        ];

        return view('sync.index', [
            'currentUser' => LocalAuth::current(),
            'waiting' => $sync->waiting()->map($label),
            'attention' => $sync->needsAttention()->map($label),
            'lastSyncAt' => AppState::get('last_sync_at'),
        ]);
    }

    /**
     * Sends everything in one request: Sync now without JavaScript. Returns
     * to the EC Board it came from (built from a looked-up center, never a
     * raw URL), else to the Sync page.
     */
    public function all(Request $request, SyncService $sync)
    {
        $center = EvacuationCenter::find($request->input('return_to_center_id'));
        $returnTo = $center
            ? route('evacuation-centers.ec-board', ['center' => $center, 'event' => $request->input('return_to_event_id')])
            : route('sync.index');

        if ($sync->unsent()->isEmpty()) {
            return redirect($returnTo)->with('status', 'Nothing to sync -- everything is already up to date.');
        }

        // Pressing Sync now also gives refused records another try.
        $sync->retryFailed();
        $summary = $sync->runAll(LocalAuth::current());

        if ($summary['stop'] === 'auth') {
            return redirect($returnTo)->with('authExpired', 'Your session has expired. Please log in again to continue syncing.');
        }

        if ($summary['sent'] === 0 && $summary['failed'] === 0 && $summary['stop'] === null) {
            // Only records waiting for the user are left (event closed, a decision).
            return redirect($returnTo)->with('status', 'Nothing to send right now.');
        }

        $message = Plural::of($summary['sent'], 'record', 'records').' sent.';
        if ($summary['failed'] > 0) {
            $message .= ' '.Plural::of($summary['failed'], 'record needs', 'records need').' attention.';
        }
        if (in_array($summary['stop'], ['offline', 'server'], true)) {
            $message .= ' '.$summary['message'].' The rest will go next time.';
        }

        return redirect($returnTo)->with('status', $message);
    }

    /** Sends one record; the page calls this until it answers empty or stopped. */
    public function step(SyncService $sync)
    {
        return response()->json($sync->step(LocalAuth::current()));
    }

    /**
     * Can the central server be reached, and is the login still good? Also
     * brings the stored permissions up to date (GET /auth/me).
     */
    public function probe(CentralApiService $api)
    {
        try {
            AuthController::refreshIdentity($api, LocalAuth::current());

            return response()->json(['reachable' => true, 'auth' => true]);
        } catch (CentralApiException $e) {
            return response()->json([
                'reachable' => ! $e->isOffline(),
                'auth' => ! in_array($e->kind, [CentralApiException::UNAUTHENTICATED, CentralApiException::FORBIDDEN], true),
            ]);
        }
    }

    /**
     * Answers the server's "possible duplicate" question for a returning
     * family: link it to a family already in the event, or add it as new.
     * The record goes back in line with the answer.
     */
    public function decide(Request $request, PendingReEnrollment $returning)
    {
        $offered = collect($returning->possible_duplicates ?? [])->pluck('family_id')->map(fn ($id) => (int) $id);
        $validated = $request->validate([
            'decision' => ['required', 'in:link,different'],
            'family_id' => ['required_if:decision,link', 'nullable', 'integer'],
        ]);
        if ($validated['decision'] === 'link' && ! $offered->contains((int) $validated['family_id'])) {
            return back()->withErrors(['family_id' => 'Choose one of the families offered.']);
        }

        $returning->update([
            'duplicate_decision' => $validated['decision'],
            'link_family_remote_id' => $validated['decision'] === 'link' ? (int) $validated['family_id'] : null,
            'sync_state' => null,
            'sync_error' => null,
        ]);

        return back()->with('status', $validated['decision'] === 'link'
            ? 'Linked. It will be sent with the next sync.'
            : 'Kept as a different family. It will be sent with the next sync.');
    }

    /** Removes a returning family not yet accepted by the server. */
    public function forgetReturning(PendingReEnrollment $returning)
    {
        abort_if($returning->isSynced(), 409);
        $returning->delete();

        return back()->with('status', 'Removed from this device.');
    }

    /** Puts refused records back in line before a run the user started. */
    public function retry(Request $request, SyncService $sync)
    {
        $sync->retryFailed($request->input('key'));

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }
}
