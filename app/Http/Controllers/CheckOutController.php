<?php

namespace App\Http\Controllers;

use App\Models\CenterFamilySnapshot;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\Family;
use App\Models\PendingCheckOut;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Whole-family check-out on the EC Board, offline: saved here and sent by
 * Sync (POST families/bulk-check-out). Per-person check-out stays on the web.
 */
class CheckOutController extends Controller
{
    /**
     * The families checked in at a center for an event, as this device
     * knows them: the server's last list plus households added here and
     * not yet sent. A family already being checked out is marked.
     *
     * @return Collection<int, array{key: string, label: string, here: ?int, purok: ?string, remote_id: ?int, local_id: ?int, checking_out: bool}>
     */
    public static function familiesHere(EvacuationCenter $center, EvacuationEvent $event): Collection
    {
        $outRemote = PendingCheckOut::where('evacuation_center_id', $center->id)->where('evacuation_event_id', $event->id)
            ->whereNotNull('family_remote_id')->pluck('family_remote_id')->map(fn ($id) => (int) $id)->all();
        $outLocal = PendingCheckOut::where('evacuation_center_id', $center->id)->where('evacuation_event_id', $event->id)
            ->whereNotNull('household_family_local_id')->pluck('household_family_local_id')->all();

        $remote = collect(CenterFamilySnapshot::for($center, $event)?->families ?? [])->map(fn (array $f) => [
            'key' => 'remote-'.$f['id'],
            'label' => empty($f['is_generic'])
                ? ($f['name'] ?? $f['head_of_family']['full_name'] ?? 'Family #'.$f['id'])
                : 'Family #'.$f['id'].' · '.($f['barangay']['name'] ?? 'another barangay'),
            'here' => $f['here_count'] ?? null,
            'purok' => $f['purok']['name'] ?? null,
            'remote_id' => (int) $f['id'],
            'local_id' => null,
            'checking_out' => in_array((int) $f['id'], $outRemote, true),
        ]);

        $local = Family::where('evacuation_center_id', $center->id)->where('evacuation_event_id', $event->id)
            ->where('created_via_ec_board', true)->whereNull('synced_at')->with('evacuees')->get()
            ->map(fn (Family $f) => [
                'key' => 'local-'.$f->id,
                'label' => $f->displayName(),
                'here' => null,
                'purok' => null,
                'remote_id' => null,
                'local_id' => $f->id,
                'checking_out' => in_array($f->id, $outLocal, true),
            ]);

        return $remote->concat($local)->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    public function store(Request $request, EvacuationCenter $center)
    {
        $validated = $request->validate([
            'evacuation_event_id' => ['required', 'integer', 'exists:evacuation_events,id'],
            'family' => ['required', 'string'],
            'status' => ['required', Rule::in(array_keys(PendingCheckOut::STATUSES))],
        ]);
        $event = EvacuationEvent::findOrFail($validated['evacuation_event_id']);
        // A closed event is read-only, the same as on the server.
        if ($event->isClosed()) {
            throw ValidationException::withMessages(['evacuation_event_id' => "\"{$event->name}\" is closed. A closed event is read-only."]);
        }
        $family = self::familiesHere($center, $event)->firstWhere('key', $validated['family']);

        if (! $family) {
            throw ValidationException::withMessages(['family' => 'That family is not checked in at this center.']);
        }
        if ($family['checking_out']) {
            throw ValidationException::withMessages(['family' => 'This family is already being checked out.']);
        }

        PendingCheckOut::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'family_remote_id' => $family['remote_id'],
            'household_family_local_id' => $family['local_id'],
            'family_label' => $family['label'],
            'status' => $validated['status'],
        ]);

        return redirect()->route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id])
            ->with('status', "{$family['label']} checked out on this device. It will be sent with the next sync.");
    }
}
