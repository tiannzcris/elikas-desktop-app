<?php

namespace App\Http\Controllers;

use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\PendingReEnrollment;
use App\Models\ReturningFamilySnapshot;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Returning families on the EC Board, offline: pick a past event, open a
 * family, tick who arrived. Read from the copy kept on this device
 * (ReturningFamilySnapshot); saved as a PendingReEnrollment that Sync sends.
 */
class ReturningFamilyController extends Controller
{
    public function index(Request $request, EvacuationCenter $center)
    {
        $event = EvacuationEvent::findOrFail($request->integer('event'));
        $snapshot = ReturningFamilySnapshot::where('evacuation_event_id', $event->id)->first();
        $pastEvents = collect($snapshot?->past_events ?? []);
        $sourceId = $request->integer('source') ?: ($pastEvents->first()['id'] ?? null);

        $queued = PendingReEnrollment::where('evacuation_event_id', $event->id)->pluck('source_family_remote_id')->all();
        $families = collect($sourceId ? $snapshot->familiesOf($sourceId) : [])
            ->map(fn (array $f) => $f + ['queued' => in_array($f['id'], $queued, true)]);

        return view('returning-families.index', [
            'currentUser' => LocalAuth::current(),
            'center' => $center,
            'event' => $event,
            'snapshot' => $snapshot,
            'pastEvents' => $pastEvents,
            'sourceId' => $sourceId,
            'families' => $families,
            'ageBrackets' => EcBoardEntry::AGE_BRACKETS,
        ]);
    }

    public function store(Request $request, EvacuationCenter $center)
    {
        $event = EvacuationEvent::findOrFail($request->integer('evacuation_event_id'));
        if ($event->isClosed()) {
            throw ValidationException::withMessages(['evacuation_event_id' => EvacuationEvent::CLOSED_TO_ARRIVALS]);
        }

        $sourceEventId = $request->integer('source_event_id');
        $snapshot = ReturningFamilySnapshot::where('evacuation_event_id', $event->id)->firstOrFail();
        $family = collect($snapshot->familiesOf($sourceEventId))->firstWhere('id', $request->integer('source_family_id'));
        if (! $family || ! empty($family['re_enrolled_family_id'])) {
            throw ValidationException::withMessages(['source_family_id' => 'This family cannot be added again. It is already in this event.']);
        }
        if (PendingReEnrollment::where('evacuation_event_id', $event->id)->where('source_family_remote_id', $family['id'])->exists()) {
            throw ValidationException::withMessages(['source_family_id' => 'This family is already waiting to be sent from this device.']);
        }

        $ticked = collect($request->input('members', []))->filter(fn ($m) => ! empty($m['ticked']));
        if ($ticked->isEmpty()) {
            throw ValidationException::withMessages(['members' => 'Tick at least one person who arrived.']);
        }

        $known = collect($family['members'])->keyBy('id');
        $members = [];
        foreach ($ticked as $id => $answers) {
            $member = $known->get((int) $id);
            if (! $member || ! empty($member['checked_in'])) {
                throw ValidationException::withMessages(['members' => 'One of the ticked people is already checked in or is not in this family.']);
            }
            $ageBracket = null;
            if (! empty($member['needs_age_bracket'])) {
                $ageBracket = $answers['age_bracket'] ?? null;
                if (! array_key_exists((string) $ageBracket, EcBoardEntry::AGE_BRACKETS)) {
                    throw ValidationException::withMessages(["members.{$id}.age_bracket" => "Choose the age group of {$member['full_name']}."]);
                }
            }
            $female = $member['sex'] === 'female';
            $members[] = array_filter([
                'evacuee_id' => (int) $member['id'],
                'full_name' => $member['full_name'],
                'sex' => $member['sex'],
                'age_bracket' => $ageBracket,
                'shown_age_bracket' => $ageBracket ?? $member['age_bracket'] ?? null,
                'is_pregnant' => $female && ! empty($answers['is_pregnant']) ? true : null,
                'is_lactating' => $female && ! empty($answers['is_lactating']) ? true : null,
            ], fn ($v) => $v !== null);
        }

        PendingReEnrollment::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'source_family_remote_id' => $family['id'],
            'family_name' => $family['name'],
            'source_event_name' => collect($snapshot->past_events)->firstWhere('id', $sourceEventId)['name'] ?? null,
            'members' => $members,
        ]);

        return redirect()->route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id])
            ->with('status', "{$family['name']} added on this device (".count($members).' '.str('person')->plural(count($members)).'). It will be sent with the next sync.');
    }
}
