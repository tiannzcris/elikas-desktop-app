<?php

namespace App\Http\Controllers;

use App\Exceptions\CentralApiException;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\PendingHandout;
use App\Services\BoxRelief;
use App\Support\EventLock;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Relief boxes (barangay officials): each round's families with the
 * suggested priority and Next in line, families staying in another
 * barangay (read-only), and Give box, which works offline through the
 * queue with client_ref.
 */
class ReliefController extends Controller
{
    public function __construct(private BoxRelief $relief) {}

    public function index(Request $request)
    {
        $auth = LocalAuth::current();
        $event = EvacuationEvent::working();
        $order = $request->query('order') === 'name' ? 'name' : 'priority';

        return view('relief.index', [
            'currentUser' => $auth,
            'event' => $event,
            'order' => $order,
            'relief' => $this->relief->forEvent($event, $request->integer('round') ?: null, $order),
            'lock' => EventLock::reason($event, EventLock::RELIEF),
            'mayGive' => $auth->can('box_relief.distribute'),
        ]);
    }

    public function refresh()
    {
        $event = EvacuationEvent::working();
        if ($event) {
            try {
                $this->relief->refresh(LocalAuth::current(), $event);
            } catch (CentralApiException $e) {
                return back()->withErrors(['refresh' => $e->isOffline() ? 'Needs a connection. Showing the list as of its last refresh.' : $e->getMessage()]);
            }
        }

        return back()->with('status', 'Brought up to date.');
    }

    /** Give box: queued with its client_ref, sent by Sync (offline too). */
    public function give(Request $request)
    {
        $auth = LocalAuth::current();
        abort_unless($auth->can('box_relief.distribute'), 403);
        $event = EvacuationEvent::working();
        EventLock::guard($event, EventLock::RELIEF);
        $validated = $request->validate(['round_id' => ['required', 'integer'], 'family_id' => ['required', 'integer']]);

        $view = $this->relief->forEvent($event, (int) $validated['round_id']);
        $round = $view['round'];
        if (! $round || $round['id'] !== (int) $validated['round_id']) {
            throw ValidationException::withMessages(['family_id' => 'This round is not on this computer. Refresh the list.']);
        }
        $family = $view['puroks']->flatMap(fn ($g) => $g['families'])->firstWhere('id', (int) $validated['family_id']);
        $label = 'Round '.($round['round_number'] ?? '');

        // The home barangay of a family staying elsewhere never gives its box (the server answers 403).
        if (! $family) {
            $away = collect($view['staying_elsewhere'])->firstWhere('id', (int) $validated['family_id']);
            throw ValidationException::withMessages(['family_id' => $away
                ? ($away['head_name'] ?? $away['name']).' is staying in '.($away['gives_box']['name'] ?? 'another barangay').', which gives the box.'
                : 'This family is not on this round\'s list on this computer. Refresh the list.']);
        }
        if ($family['is_given']) {
            throw ValidationException::withMessages(['family_id' => "This family already got its box for {$label}."]);
        }
        if ($view['figures']['received'] <= 0) {
            throw ValidationException::withMessages(['family_id' => "No boxes have been received for {$label} yet."]);
        }
        if ($view['figures']['left_to_give'] <= 0) {
            throw ValidationException::withMessages(['family_id' => 'All '.number_format($view['figures']['received'])." boxes received for {$label} have been given."]);
        }

        $name = $family['head_name'] ?? $family['name'] ?? 'Family #'.$family['id'];
        PendingHandout::create([
            'evacuation_event_id' => $event->id,
            'round_remote_id' => $round['id'],
            'round_number' => $round['round_number'],
            'family_remote_id' => $family['id'],
            'family_label' => $name,
            'higher_priority_waiting' => $family['higher_priority_waiting'],
        ]);

        return back()->with('status', "Box given to {$name}. It is sent with the next sync.");
    }
}
