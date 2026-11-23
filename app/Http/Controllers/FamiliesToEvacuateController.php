<?php

namespace App\Http\Controllers;

use App\Exceptions\CentralApiException;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\PendingListAddition;
use App\Models\PendingListCheckIn;
use App\Models\Purok;
use App\Services\FamiliesToEvacuate;
use App\Support\EventLock;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Families to evacuate, for barangay officials: list the families each
 * purok expects to move (while the event is monitored or active), fast,
 * from the keyboard; mark high-risk puroks; and once the event is active,
 * check a listed family in (queued, offline too).
 */
class FamiliesToEvacuateController extends Controller
{
    private const OFFLINE = 'Needs a connection. Removing and the high-risk marks go to the server at once; adding and checking in work offline.';

    public function __construct(private FamiliesToEvacuate $list) {}

    public function index(Request $request)
    {
        $auth = LocalAuth::current();
        $event = EvacuationEvent::working();
        $puroks = Purok::where('barangay_remote_id', $auth->barangay_id)->orderBy('name')->get()
            ->sortBy(fn (Purok $p) => [$p->isHighRisk() ? 0 : 1, mb_strtolower($p->name)])->values();

        // Searching past events is a live call, made only when asked for.
        $past = null;
        $pastError = null;
        $search = trim((string) $request->query('search', ''));
        if ($search !== '' && $this->mayEdit($event)) {
            try {
                $past = $this->list->pastFamilies($auth, $search);
            } catch (CentralApiException $e) {
                $pastError = $e->isOffline() ? 'Needs a connection to search past events.' : $e->getMessage();
            }
        }

        return view('families-to-evacuate.index', [
            'currentUser' => $auth,
            'event' => $event,
            'list' => $this->list->forEvent($event, (int) $auth->barangay_id),
            'puroks' => $puroks,
            'selectedPurok' => $request->integer('purok') ?: null,
            'mayEdit' => $this->mayEdit($event),
            'mayCheckIn' => $event?->isActive() && $auth->can('families_to_evacuate.manage'),
            'centers' => EvacuationCenter::where('barangay_remote_id', $auth->barangay_id)->where('status', '!=', 'closed')
                ->where('approval_status', '!=', 'rejected')->orderBy('name')->get(),
            'search' => $search,
            'past' => $past,
            'pastError' => $pastError,
        ]);
    }

    /** Listing is allowed while the event is monitored or active. */
    private function mayEdit(?EvacuationEvent $event): bool
    {
        return $event !== null && ! $event->isClosed() && LocalAuth::current()->can('families_to_evacuate.manage');
    }

    private function editableEvent(): EvacuationEvent
    {
        abort_unless(LocalAuth::current()->can('families_to_evacuate.manage'), 403);
        $event = EvacuationEvent::working();
        if (! $event || $event->isClosed()) {
            throw ValidationException::withMessages(['evacuation_event_id' => EventLock::reason($event, 'Families to evacuate')]);
        }

        return $event;
    }

    public function store(Request $request)
    {
        $auth = LocalAuth::current();
        $event = $this->editableEvent();
        $purokRule = ['nullable', 'integer', Rule::exists('puroks', 'id')->where('barangay_remote_id', $auth->barangay_id)];

        if ($request->filled('source_family_id')) {
            $validated = $request->validate(['source_family_id' => ['required', 'integer'], 'purok_id' => $purokRule, 'label' => ['nullable', 'string', 'max:200'],
                'members_count' => ['nullable', 'integer', 'min:1', 'max:30']]);
            $fields = ['source_family_id' => (int) $validated['source_family_id'], 'members_count' => (int) ($validated['members_count'] ?? 1)];
        } else {
            $validated = $request->validate([
                'head_first_name' => ['required', 'string', 'max:100'],
                'head_last_name' => ['required', 'string', 'max:100'],
                'head_sex' => ['nullable', Rule::in(['male', 'female'])],
                'members_count' => ['required', 'integer', 'min:1', 'max:30'],
                'purok_id' => $purokRule,
                'contact_number' => ['nullable', 'string', 'max:30'],
                'note' => ['nullable', 'string', 'max:255'],
            ], [
                'members_count.max' => 'A family can have at most 30 members on the list.',
            ]);
            $fields = collect($validated)->except('purok_id')->all();
        }
        $purok = ! empty($validated['purok_id']) ? Purok::find($validated['purok_id']) : null;
        $name = trim(($validated['head_first_name'] ?? '').' '.($validated['head_last_name'] ?? '')) ?: ($validated['label'] ?? 'A family from a past event');

        // Saved here first, sent by Sync with its client_ref (offline too).
        PendingListAddition::create($fields + [
            'evacuation_event_id' => $event->id,
            'purok_remote_id' => $purok?->remote_id,
            'label' => $name,
        ]);

        // Back to the same purok, ready for the next family.
        return redirect()->route('families-to-evacuate.index', array_filter(['purok' => $purok?->id]))
            ->with('status', "{$name} added to the list".($purok ? " ({$purok->name})" : '').'. It is sent with the next sync.')
            ->with('listAdded', true);
    }

    public function destroy(string $listedId)
    {
        $auth = LocalAuth::current();
        $event = $this->editableEvent();

        // Added here and not sent yet: only this computer has it.
        if (str_starts_with($listedId, 'local-')) {
            $addition = PendingListAddition::findOrFail((int) substr($listedId, 6));
            if ($addition->synced_at === null) {
                if (PendingListCheckIn::where('pending_list_addition_id', $addition->id)->exists()) {
                    return back()->withErrors(['list' => 'This family is already checked in on this computer.']);
                }
                $addition->delete();

                return back()->with('status', 'Removed from the list.');
            }
            $listedId = (string) $addition->listed_family_remote_id;
        }
        $listedId = (int) $listedId;
        try {
            $this->list->remove($auth, $listedId);
        } catch (CentralApiException $e) {
            return back()->withErrors(['list' => $e->isOffline() ? self::OFFLINE : $e->getMessage()]);
        }
        $this->reload($auth, $event);

        return back()->with('status', 'Removed from the list.');
    }

    /** PATCH /puroks/{id}: high risk of flood and/or landslide. */
    public function markPurok(Request $request, Purok $purok)
    {
        $auth = LocalAuth::current();
        abort_unless($auth->isBarangayOfficial() && (int) $purok->barangay_remote_id === (int) $auth->barangay_id, 403);
        $flood = $request->boolean('high_risk_flood');
        $landslide = $request->boolean('high_risk_landslide');

        try {
            $this->list->markPurok($auth, $purok, $flood, $landslide);
        } catch (CentralApiException $e) {
            return back()->withErrors(['purok' => $e->isOffline() ? self::OFFLINE : $e->getMessage()]);
        }

        return back()->with('status', $purok->isHighRisk() ? "{$purok->name} marked high risk ({$purok->riskLabel()})." : "{$purok->name} is no longer marked high risk.");
    }

    /**
     * Checks a listed family in (Active only): queued on this device with its
     * client_ref and sent by Sync. At one of the barangay's own centers, or in
     * a private house (host name, plus a purok or an address).
     */
    public function checkIn(Request $request, string $listedId)
    {
        $auth = LocalAuth::current();
        abort_unless($auth->can('families_to_evacuate.manage'), 403);
        $event = EvacuationEvent::working();
        EventLock::guard($event, EventLock::CHECK_IN);

        $validated = $request->validate([
            'destination' => ['required', 'string'],
            'host_name' => ['nullable', 'required_if:destination,private', 'string', 'max:150'],
            'host_purok_id' => ['nullable', 'integer', Rule::exists('puroks', 'id')->where('barangay_remote_id', $auth->barangay_id)],
            'host_address' => ['nullable', 'string', 'max:255'],
        ], [
            'host_name.required_if' => 'Say who the family is staying with.',
        ]);

        $key = str_starts_with($listedId, 'local-') ? $listedId : (int) $listedId;
        $row = collect($this->list->forEvent($event, (int) $auth->barangay_id)['groups'])->flatMap(fn ($g) => $g['families'])->firstWhere('id', $key);
        if (! $row) {
            throw ValidationException::withMessages(['destination' => 'This family is not on the list on this device. Refresh the list.']);
        }
        if ($row['is_checked_in']) {
            throw ValidationException::withMessages(['destination' => "{$row['head']} is already checked in."]);
        }

        $center = null;
        if ($validated['destination'] === 'private') {
            if (empty($validated['host_purok_id']) && trim((string) ($validated['host_address'] ?? '')) === '') {
                throw ValidationException::withMessages(['host_address' => 'Choose the host\'s purok or type the address.']);
            }
        } else {
            $center = EvacuationCenter::where('barangay_remote_id', $auth->barangay_id)->where('approval_status', '!=', 'rejected')
                ->find((int) str_replace('center-', '', $validated['destination']));
            if (! $center) {
                throw ValidationException::withMessages(['destination' => 'Choose one of your barangay\'s centers.']);
            }
        }

        PendingListCheckIn::create([
            'evacuation_event_id' => $event->id,
            // A family added here and not sent yet: the check-in waits for it.
            'listed_family_remote_id' => is_int($key) ? $key : $row['added_here']->listed_family_remote_id,
            'pending_list_addition_id' => is_int($key) ? null : $row['added_here']->id,
            'family_label' => $row['head'] ?? 'Family #'.$listedId,
            'members_count' => max(1, (int) $row['members_count']),
            'evacuation_center_id' => $center?->id,
            'host_name' => $center ? null : $validated['host_name'],
            'host_purok_remote_id' => $center || empty($validated['host_purok_id']) ? null : Purok::find($validated['host_purok_id'])?->remote_id,
            'host_address' => $center ? null : ($validated['host_address'] ?? null),
        ]);

        return back()->with('status', "{$row['head']} checked in at ".($center?->name ?? 'a private house').'. It is sent with the next sync.');
    }

    public function refresh()
    {
        $auth = LocalAuth::current();
        $event = EvacuationEvent::working();
        if ($event) {
            try {
                $this->list->refresh($auth, $event);
            } catch (CentralApiException $e) {
                return back()->withErrors(['refresh' => $e->isOffline() ? 'Needs a connection. Showing the list as of its last refresh.' : $e->getMessage()]);
            }
        }

        return back()->with('status', 'Brought up to date.');
    }

    private function reload(LocalAuth $auth, EvacuationEvent $event): void
    {
        try {
            $this->list->refresh($auth, $event);
        } catch (CentralApiException $e) {
            // Done on the server; the copy catches up at the next refresh.
        }
    }
}
