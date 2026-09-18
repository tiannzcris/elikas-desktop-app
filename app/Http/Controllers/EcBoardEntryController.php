<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddEvacueeRequest;
use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\Evacuee;
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

        // A brand-new household needs a real local Family+Evacuee row
        // created FIRST (see createNewHousehold()'s own docblock for why),
        // so this entry can be saved pointing at it via
        // household_family_local_id instead of the old plain-text-only
        // new_household_head_name -- otherwise this household could never
        // become selectable as "existing" for a second evacuee added
        // moments later at the same center, confirmed missing before this.
        $householdFields = $validated['household_type'] === 'new'
            ? $this->createNewHousehold($center, $validated)
            : $request->householdFields();

        EcBoardEntry::create(array_merge([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $validated['evacuation_event_id'],
            'sex' => $validated['sex'],
            'age_bracket' => $validated['age_bracket'],
        ], $householdFields));

        return redirect()->route('evacuation-centers.ec-board', ['center' => $center, 'event' => $validated['evacuation_event_id']])
            ->with('status', 'Evacuee added on this device. Sync when you have internet.');
    }

    /**
     * Creates a real local Family+Evacuee for a "new household" Add
     * Evacuee submission, so it shows up correctly in the household
     * picker (a real name, not "Household #N") and becomes selectable as
     * an existing household for a later evacuee at this same center --
     * confirmed genuinely missing before this (no Family::create() ever
     * ran for this path).
     *
     * This Family is marked created_via_ec_board and deliberately never
     * enters FamilyController::sync()'s own registerFamily() loop -- the
     * real /families/register endpoint requires date_of_birth AND
     * contact_number for every member (confirmed against the backend's
     * own RegisterFamilyRequest), neither of which "Add Evacuee" ever
     * collects. Its Evacuee row's date_of_birth is left null for exactly
     * that reason: it is a LOCAL-ONLY placeholder, never sent to that
     * endpoint. This household still reaches the real central server --
     * through the RETURNING EcBoardEntry's own addEvacuee() sync call
     * (household_mode: 'new', unchanged from before this Family existed
     * locally at all), flagged via originated_household so
     * EcBoardEntry::toSyncPayload() and FamilyController::sync() both
     * know this specific entry is what must carry that sync, not a
     * separate registerFamily() call.
     *
     * @return array{household_family_local_id: int, existing_household_remote_id: null, new_household_head_name: null, originated_household: true}
     */
    private function createNewHousehold(EvacuationCenter $center, array $validated): array
    {
        $barangay = Barangay::where('remote_id', $center->barangay_remote_id)->firstOrFail();

        $family = Family::create([
            'barangay_id' => $barangay->id,
            'evacuation_event_id' => $validated['evacuation_event_id'],
            'evacuation_center_id' => $center->id,
            'displacement_type' => 'inside_center',
            'created_via_ec_board' => true,
        ]);

        // Splitting purely by "first word" vs "the rest" (not a real
        // first/last name parser) deliberately preserves multi-word
        // surnames common in Filipino names (e.g. "Dela Cruz", "De
        // Guzman") -- full_name's own "{first} {last}" concatenation
        // always reconstructs the exact name that was typed, which is
        // all this placeholder row needs: a correct DISPLAY label, not a
        // linguistically accurate split (this row is never synced with
        // these two fields separately -- see this method's own docblock).
        $nameParts = preg_split('/\s+/', trim($validated['new_household_head_name']), 2);

        Evacuee::create([
            'family_id' => $family->id,
            'first_name' => $nameParts[0],
            'last_name' => $nameParts[1] ?? '',
            'sex' => $validated['sex'],
            'is_head_of_family' => true,
        ]);

        return [
            'household_family_local_id' => $family->id,
            'existing_household_remote_id' => null,
            'new_household_head_name' => null,
            'originated_household' => true,
        ];
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
        $originatedFamilyId = $entry->originated_household ? $entry->household_family_local_id : null;
        $entry->delete();

        // The Family this entry created has no other way to ever sync
        // (see its own created_via_ec_board docblock) -- if nothing else
        // still references it, remove it too rather than leaving an
        // orphaned, permanently-"pending" row behind on the Registered
        // Families page.
        if ($originatedFamilyId && ! EcBoardEntry::where('household_family_local_id', $originatedFamilyId)->exists()) {
            Family::whereKey($originatedFamilyId)->delete();
        }

        return redirect()->route('evacuation-centers.ec-board', $centerId)
            ->with('status', 'Pending entry removed.');
    }
}
