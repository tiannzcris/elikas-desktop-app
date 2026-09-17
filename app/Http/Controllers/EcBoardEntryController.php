<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddEvacueeRequest;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\Family;
use App\Models\LocalAuth;
use Illuminate\Http\Request;

class EcBoardEntryController extends Controller
{
    /**
     * Saves entirely to the LOCAL database, same as FamilyController::
     * store() -- no API call, synced_at stays null until a manual sync.
     */
    public function store(AddEvacueeRequest $request, EvacuationCenter $center)
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }

        $validated = $request->validated();

        EcBoardEntry::create(array_merge([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $validated['evacuation_event_id'],
            'sex' => $validated['sex'],
            'age_bracket' => $validated['age_bracket'],
        ], $request->householdFields()));

        return redirect()->route('evacuation-centers.ec-board', ['center' => $center, 'event' => $validated['evacuation_event_id']])
            ->with('status', 'Evacuee added on this device. Sync when you have internet.');
    }

    /**
     * Shared by store() [inline on the center page] and edit() [a modal] --
     * identical form partial either way, exactly mirroring FamilyController's
     * renderForm() split.
     */
    private function renderForm(Request $request, LocalAuth $auth, EvacuationCenter $center, ?EcBoardEntry $entry = null)
    {
        $data = [
            'center' => $center,
            'entry' => $entry,
            'events' => EvacuationEvent::where('status', '!=', 'closed')->orderByDesc('id')->get(),
            'households' => Family::where('evacuation_center_id', $center->id)->with('evacuees')->get(),
            'ageBrackets' => EcBoardEntry::AGE_BRACKETS,
        ];

        if ($request->header('X-Modal-Request')) {
            return view('evacuation-centers._entry_form', $data);
        }

        return view('evacuation-centers.edit-entry', $data);
    }

    /**
     * Scoped to not-yet-synced entries only -- once synced, the central
     * server is the source of truth, exactly matching FamilyController::
     * edit()'s reasoning.
     */
    public function edit(Request $request, EcBoardEntry $entry)
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }

        if ($entry->isSynced()) {
            return redirect()->route('evacuation-centers.ec-board', $entry->evacuation_center_id)
                ->with('status', 'This entry has already synced -- it can no longer be edited from this device.');
        }

        return $this->renderForm($request, $auth, $entry->evacuationCenter, $entry);
    }

    public function update(AddEvacueeRequest $request, EcBoardEntry $entry)
    {
        if ($entry->isSynced()) {
            return redirect()->route('evacuation-centers.ec-board', $entry->evacuation_center_id)
                ->with('status', 'This entry has already synced -- it can no longer be edited from this device.');
        }

        $validated = $request->validated();

        $entry->update(array_merge([
            'evacuation_event_id' => $validated['evacuation_event_id'],
            'sex' => $validated['sex'],
            'age_bracket' => $validated['age_bracket'],
            // Clears out whatever validation error sent this record back
            // here in the first place -- it's about to get fresh data,
            // same reasoning as FamilyController::update().
            'sync_error' => null,
        ], $request->householdFields()));

        return redirect()->route('evacuation-centers.ec-board', ['center' => $entry->evacuation_center_id, 'event' => $entry->evacuation_event_id])
            ->with('status', 'Entry updated on this device. Sync when you have internet.');
    }

    public function destroy(EcBoardEntry $entry)
    {
        $auth = LocalAuth::current();
        if (! $auth) {
            return redirect()->route('login');
        }

        if ($entry->isSynced()) {
            return redirect()->route('evacuation-centers.ec-board', $entry->evacuation_center_id)
                ->with('status', 'This entry has already synced -- it can no longer be deleted from this device.');
        }

        $centerId = $entry->evacuation_center_id;
        $entry->delete();

        return redirect()->route('evacuation-centers.ec-board', $centerId)
            ->with('status', 'Pending entry removed.');
    }
}
