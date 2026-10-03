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
use Illuminate\Support\Facades\DB;

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

        DB::transaction(function () use ($request, $validated, $center) {
            // A brand-new household needs a real local Family created FIRST
            // (see createNewHousehold()'s own docblock for why), so this
            // entry can point at it via household_family_local_id -- that's
            // what makes it selectable as "already here" for the next person.
            $householdFields = $validated['household_type'] === 'new'
                ? $this->createNewHousehold($center, $request)
                : $request->householdFields();

            $entry = EcBoardEntry::create(array_merge([
                'evacuation_center_id' => $center->id,
                'evacuation_event_id' => $validated['evacuation_event_id'],
                'sex' => $validated['sex'],
                'age_bracket' => $validated['age_bracket'],
            ], $request->sectoralFields(), $householdFields));

            $this->applyHeadLink($entry, $request->headIsSelf());
            $this->syncPlaceholderHead($entry);
        });

        return redirect()->route('evacuation-centers.ec-board', ['center' => $center, 'event' => $validated['evacuation_event_id']])
            ->with('status', 'Evacuee added on this device. Sync when you have internet.')
            // Reopens the Add evacuee pop-up after the reload, ready for
            // the next person (see evacuation-centers/ec-board.blade.php).
            ->with('ecBoardEntryAdded', true);
    }

    /**
     * Creates a real local Family for a "new household" Add Evacuee
     * submission, carrying the household's one-time answers (single-headed,
     * and the head's sex/minor when the head is someone else) exactly as
     * the central server's addEvacuee() stores them on its own Family.
     *
     * This Family is marked created_via_ec_board and never enters
     * FamilyController::sync()'s registerFamily() loop -- that endpoint
     * requires date_of_birth and contact_number per member, which Add
     * Evacuee never collects. It reaches the central server through the
     * originating EcBoardEntry's own addEvacuee() call instead (household_
     * mode "new"), flagged via originated_household.
     *
     * @return array{household_family_local_id: int, existing_household_remote_id: null, new_household_head_name: null, originated_household: true}
     */
    private function createNewHousehold(EvacuationCenter $center, AddEvacueeRequest $request): array
    {
        $barangay = Barangay::where('remote_id', $center->barangay_remote_id)->firstOrFail();

        $family = Family::create(array_merge([
            'barangay_id' => $barangay->id,
            'evacuation_event_id' => $request->input('evacuation_event_id'),
            'evacuation_center_id' => $center->id,
            'displacement_type' => 'inside_center',
            'created_via_ec_board' => true,
            'name' => trim($request->input('new_household_head_name')),
        ], $request->newHouseholdAnswers()));

        return [
            'household_family_local_id' => $family->id,
            'existing_household_remote_id' => null,
            'new_household_head_name' => null,
            'originated_household' => true,
        ];
    }

    /**
     * Links (or unlinks) $entry as its household's head -- the one place
     * this rule lives, mirroring the central server's addEvacuee(): a head
     * is only ever filled when the household has NONE linked yet, and an
     * existing head is never replaced from here. For a household known only
     * on the central server (no local row), the wish is recorded on the
     * entry and the server applies that same rule when it syncs.
     */
    private function applyHeadLink(EcBoardEntry $entry, bool $wantsHead): void
    {
        if ($entry->existing_household_remote_id) {
            $entry->update(['head_is_self' => $wantsHead]);

            return;
        }

        $family = $entry->household()->first();
        if (! $family) {
            $entry->update(['head_is_self' => false]);

            return;
        }

        $isThisEntryHead = (int) $family->head_ec_board_entry_id === $entry->id;
        $linked = $wantsHead && ($isThisEntryHead || ! $family->hasLinkedHead());

        if ($linked && ! $isThisEntryHead) {
            $family->update(['head_ec_board_entry_id' => $entry->id]);
        } elseif (! $linked && $isThisEntryHead) {
            $family->update(['head_ec_board_entry_id' => null]);
        }

        $entry->update(['head_is_self' => $linked]);
    }

    /**
     * Keeps the local-only head member row of a household this device
     * created in step with who its head is. That row exists purely so the
     * household reads by its head's name in search and on family cards --
     * and it only exists when the head IS the person added (their sex is
     * known). For "someone else is the head", no row is invented: the
     * household is labelled by Family::name instead, rather than recording
     * a head member with a guessed sex.
     */
    private function syncPlaceholderHead(EcBoardEntry $entry): void
    {
        if (! $entry->originated_household) {
            return;
        }

        $family = $entry->household()->first();
        if (! $family) {
            return;
        }

        $placeholder = $family->evacuees()->where('is_head_of_family', true)->first();

        if (! $entry->head_is_self) {
            $placeholder?->delete();

            return;
        }

        // Split on the first space only, preserving multi-word surnames
        // ("Dela Cruz") -- full_name rejoins them exactly as typed.
        $nameParts = preg_split('/\s+/', trim((string) $family->name), 2);

        Evacuee::updateOrCreate(
            ['family_id' => $family->id, 'is_head_of_family' => true],
            ['first_name' => $nameParts[0], 'last_name' => $nameParts[1] ?? '', 'sex' => $entry->sex]
        );
    }

    /**
     * Shared by the inline "Add Evacuee" card and the edit modal --
     * identical form partial either way.
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

        DB::transaction(function () use ($request, $validated, $entry) {
            $fields = array_merge([
                'evacuation_event_id' => $validated['evacuation_event_id'],
                'sex' => $validated['sex'],
                'age_bracket' => $validated['age_bracket'],
                // Clears whatever validation error sent this record back
                // here -- it's about to get fresh data.
                'sync_error' => null,
            ], $request->sectoralFields());

            if ($entry->originated_household) {
                // The entry that CREATES its household stays tied to it (other
                // entries may already have joined it) -- only the household's
                // own name and answers are corrected, never re-pointed.
                $entry->household()->first()?->update(array_merge(
                    ['name' => trim((string) $request->input('new_household_head_name'))],
                    $request->newHouseholdAnswers()
                ));
            } else {
                // Moving this person to a different household must not leave
                // them recorded as the OLD household's head.
                $oldFamilyId = $entry->household_family_local_id;
                $fields = array_merge($fields, $request->householdFields());
                if ($oldFamilyId && $oldFamilyId !== ($fields['household_family_local_id'] ?? null)) {
                    Family::whereKey($oldFamilyId)->where('head_ec_board_entry_id', $entry->id)
                        ->update(['head_ec_board_entry_id' => null]);
                }
            }

            $entry->update($fields);
            $this->applyHeadLink($entry, $request->headIsSelf());
            $this->syncPlaceholderHead($entry);
        });

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

        DB::transaction(function () use ($entry, $originatedFamilyId) {
            // The household goes back to "head not yet linked" -- its own
            // head_sex/head_is_minor answers, kept all along, apply again.
            Family::where('head_ec_board_entry_id', $entry->id)->update(['head_ec_board_entry_id' => null]);
            $entry->delete();

            // The Family this entry created has no other way to ever sync --
            // if nothing else still references it, remove it too rather than
            // leaving an orphaned, permanently-"pending" row behind.
            if ($originatedFamilyId && ! EcBoardEntry::where('household_family_local_id', $originatedFamilyId)->exists()) {
                Evacuee::where('family_id', $originatedFamilyId)->delete();
                Family::whereKey($originatedFamilyId)->delete();
            }
        });

        return redirect()->route('evacuation-centers.ec-board', $centerId)
            ->with('status', 'Pending entry removed.');
    }
}
