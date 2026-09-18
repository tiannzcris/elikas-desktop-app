<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\Family;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers the confirmed-missing behavior: adding an evacuee offline with a
 * NEW household never created a real local Family, so that household
 * could never be picked as "existing" for a second evacuee moments later
 * at the same center -- see EcBoardEntryController::createNewHousehold()'s
 * own docblock for why this couldn't just reuse the full registration
 * sync path (the real /families/register endpoint requires date_of_birth
 * and contact_number per member, neither of which "Add Evacuee" collects).
 */
class EcBoardNewHouseholdCreatesFamilyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Barangay, 1: EvacuationEvent, 2: EvacuationCenter} */
    private function seedBase(): array
    {
        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official',
            'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);
        $barangay = Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Barangay Hall', 'status' => 'active']);

        return [$barangay, $event, $center];
    }

    public function test_a_new_household_creates_a_real_local_family_and_evacuee(): void
    {
        [$barangay, $event, $center] = $this->seedBase();

        $this->post(route('ec-board-entries.store', $center), [
            'evacuation_event_id' => $event->id,
            'sex' => 'male',
            'age_bracket' => 'adult',
            'household_type' => 'new',
            'new_household_head_name' => 'Juan Dela Cruz',
        ]);

        $this->assertSame(1, Family::count());
        $family = Family::first();
        $this->assertTrue($family->created_via_ec_board);
        $this->assertSame($barangay->id, $family->barangay_id);
        $this->assertSame($center->id, $family->evacuation_center_id);
        $this->assertSame('inside_center', $family->displacement_type);
        $this->assertNull($family->synced_at);

        $this->assertSame(1, $family->evacuees()->count());
        $head = $family->evacuees()->first();
        $this->assertSame('Juan', $head->first_name);
        $this->assertSame('Dela Cruz', $head->last_name);
        $this->assertTrue($head->is_head_of_family);
        $this->assertNull($head->date_of_birth);
    }

    public function test_a_second_evacuee_can_select_the_first_new_household_as_existing_at_the_same_center(): void
    {
        [$barangay, $event, $center] = $this->seedBase();

        $this->post(route('ec-board-entries.store', $center), [
            'evacuation_event_id' => $event->id,
            'sex' => 'male',
            'age_bracket' => 'adult',
            'household_type' => 'new',
            'new_household_head_name' => 'Juan Dela Cruz',
        ]);
        $family = Family::firstOrFail();

        // Reopening the Add Evacuee form for this SAME center must now
        // offer "Juan Dela Cruz" as a real, selectable existing household
        // -- confirmed via the actual rendered <select>, not just that the
        // Family row exists.
        $page = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));
        preg_match('/<select name="household_family_local_id"[^>]*>(.*?)<\/select>/s', $page->getContent(), $m);
        $this->assertStringContainsString('Juan Dela Cruz', $m[1] ?? '');
        $this->assertStringContainsString('value="'.$family->id.'"', $m[1] ?? '');

        $second = $this->post(route('ec-board-entries.store', $center), [
            'evacuation_event_id' => $event->id,
            'sex' => 'female',
            'age_bracket' => 'adult',
            'household_type' => 'existing',
            'household_family_local_id' => (string) $family->id,
        ]);
        $second->assertRedirect(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));

        $this->assertSame(1, Family::count(), 'a second member of the same household must not create a second Family');
        $this->assertDatabaseHas('ec_board_entries', [
            'household_family_local_id' => $family->id,
            'originated_household' => false,
            'sex' => 'female',
        ]);
    }

    public function test_syncing_the_originating_entry_and_a_second_existing_entry_both_reach_the_real_backend_correctly(): void
    {
        [$barangay, $event, $center] = $this->seedBase();

        $this->post(route('ec-board-entries.store', $center), [
            'evacuation_event_id' => $event->id,
            'sex' => 'male',
            'age_bracket' => 'adult',
            'household_type' => 'new',
            'new_household_head_name' => 'Juan Dela Cruz',
        ]);
        $family = Family::firstOrFail();

        $this->post(route('ec-board-entries.store', $center), [
            'evacuation_event_id' => $event->id,
            'sex' => 'female',
            'age_bracket' => 'adult',
            'household_type' => 'existing',
            'household_family_local_id' => (string) $family->id,
        ]);

        // Call 1 (the originating entry, processed first -- see
        // FamilyController::sync()'s orderByDesc('originated_household'))
        // creates the household on the real server: data.id (500) is the
        // NEW family's remote id, data.evacuee_id (900) is Juan's own.
        // Call 2 (the second member) must reuse that exact family id.
        Http::fake(['*/evacuation-centers/*/evacuees' => Http::sequence()
            ->push(['data' => ['id' => 500, 'evacuee_id' => 900]], 201)
            ->push(['data' => ['id' => 500, 'evacuee_id' => 901]], 201),
        ]);

        $response = $this->post(route('families.sync'));
        $response->assertSessionHas('status', '2 record(s) synced successfully.');

        $family->refresh();
        $this->assertSame(500, $family->remote_id);
        $this->assertNotNull($family->synced_at);
        $this->assertNull($family->sync_error);

        $entries = EcBoardEntry::orderBy('id')->get();
        $this->assertSame(900, $entries[0]->remote_id);
        $this->assertSame(901, $entries[1]->remote_id);
        $this->assertNotNull($entries[0]->synced_at);
        $this->assertNotNull($entries[1]->synced_at);

        // The exact payloads sent -- the originating entry as a brand-new
        // household, the second as existing, reusing family_id 500 (the
        // FIRST call's own response), never a fabricated family id.
        Http::assertSent(fn ($request) => $request['household_mode'] === 'new'
            && $request['family_name'] === 'Juan Dela Cruz'
            && $request['barangay_id'] === $barangay->remote_id);
        Http::assertSent(fn ($request) => $request['household_mode'] === 'existing'
            && $request['family_id'] === 500);
    }

    public function test_deleting_the_originating_entry_removes_its_now_orphaned_placeholder_family(): void
    {
        [$barangay, $event, $center] = $this->seedBase();

        $this->post(route('ec-board-entries.store', $center), [
            'evacuation_event_id' => $event->id,
            'sex' => 'male',
            'age_bracket' => 'adult',
            'household_type' => 'new',
            'new_household_head_name' => 'Juan Dela Cruz',
        ]);
        $entry = EcBoardEntry::firstOrFail();

        $this->delete(route('ec-board-entries.destroy', $entry));

        $this->assertSame(0, Family::count());
        $this->assertSame(0, \App\Models\Evacuee::count());
    }

    public function test_deleting_one_entry_keeps_the_family_when_a_second_entry_still_references_it(): void
    {
        [$barangay, $event, $center] = $this->seedBase();

        $this->post(route('ec-board-entries.store', $center), [
            'evacuation_event_id' => $event->id,
            'sex' => 'male',
            'age_bracket' => 'adult',
            'household_type' => 'new',
            'new_household_head_name' => 'Juan Dela Cruz',
        ]);
        $family = Family::firstOrFail();
        $originatingEntry = EcBoardEntry::firstOrFail();

        $this->post(route('ec-board-entries.store', $center), [
            'evacuation_event_id' => $event->id,
            'sex' => 'female',
            'age_bracket' => 'adult',
            'household_type' => 'existing',
            'household_family_local_id' => (string) $family->id,
        ]);

        $this->delete(route('ec-board-entries.destroy', $originatingEntry));

        $this->assertSame(1, Family::count(), 'the family must survive -- a second entry still references it');
    }
}
