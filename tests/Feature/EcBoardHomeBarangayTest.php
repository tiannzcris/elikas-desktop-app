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
 * Add evacuee's "Home barangay" for a new family: where the family lives,
 * not where the center is -- required, no default, sent to the central
 * server as the family's barangay_id (the same field as the web
 * dashboard's Add Evacuee).
 */
class EcBoardHomeBarangayTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: EvacuationEvent, 1: EvacuationCenter, 2: Barangay, 3: Barangay} */
    private function seedBase(): array
    {
        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official',
            'barangay_id' => 4, 'barangay_name' => 'Bacong', 'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);
        $bacong = Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $tupas = Barangay::create(['remote_id' => 55, 'name' => 'Tupas']);
        $event = EvacuationEvent::create(['remote_id' => 7, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 4, 'name' => 'Bacong Gym', 'status' => 'active']);

        return [$event, $center, $bacong, $tupas];
    }

    private function newFamily(EvacuationEvent $event, EvacuationCenter $center, array $overrides = [])
    {
        return $this->post(route('ec-board-entries.store', $center), array_merge([
            'evacuation_event_id' => $event->id,
            'sex' => 'female',
            'age_bracket' => 'adult',
            'household_type' => 'new',
            'new_household_head_name' => 'Rosario Magbanua',
            'head_is_self' => '1',
        ], $overrides));
    }

    public function test_the_form_asks_for_the_home_barangay_with_no_default(): void
    {
        [$event, $center] = $this->seedBase();

        $page = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));

        $page->assertOk();
        $page->assertSeeInOrder(['Home barangay', 'Same as this center (Bacong)', "Choose the family's home barangay...", 'Bacong', 'Tupas', 'Where the family lives -- not necessarily where this center is.', 'Family name'], false);
        $this->assertDoesNotMatchRegularExpression('/<option value="\d+"\s+selected[^>]*>(Bacong|Tupas)</', $page->getContent());
    }

    public function test_a_new_family_without_a_home_barangay_is_refused_with_the_servers_message(): void
    {
        [$event, $center] = $this->seedBase();

        $this->newFamily($event, $center)->assertSessionHasErrors(['new_household_barangay_id' => "Choose the family's home barangay."]);

        $this->assertSame(0, Family::count());
        $this->assertSame(0, EcBoardEntry::count());
    }

    public function test_the_chosen_barangay_is_the_familys_even_when_the_center_is_elsewhere_and_is_what_syncs(): void
    {
        [$event, $center, , $tupas] = $this->seedBase();

        $this->newFamily($event, $center, ['new_household_barangay_id' => $tupas->id])->assertSessionHasNoErrors();

        $family = Family::sole();
        $this->assertSame($tupas->id, $family->barangay_id);
        $this->assertSame($center->id, $family->evacuation_center_id);
        $this->assertSame(55, EcBoardEntry::sole()->toSyncPayload()['barangay_id']);

        Http::fake(['*/evacuation-centers/1/evacuees' => Http::response(['data' => ['id' => 700], 'evacuee_id' => 900], 201)]);
        $this->post(route('families.sync'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/evacuation-centers/1/evacuees')
            && $request['household_mode'] === 'new' && $request['barangay_id'] === 55);
    }

    public function test_same_as_this_center_is_just_a_choice_and_editing_keeps_or_changes_it(): void
    {
        [$event, $center, $bacong, $tupas] = $this->seedBase();
        $this->newFamily($event, $center, ['new_household_barangay_id' => $bacong->id])->assertSessionHasNoErrors();
        $entry = EcBoardEntry::sole();

        $edit = $this->get(route('ec-board-entries.edit', $entry));
        $edit->assertSee('<option value="'.$bacong->id.'" selected>Bacong</option>', false);

        $this->put(route('ec-board-entries.update', $entry), [
            'evacuation_event_id' => $event->id, 'sex' => 'female', 'age_bracket' => 'adult',
            'household_type' => 'new', 'new_household_head_name' => 'Rosario Magbanua', 'head_is_self' => '1',
            'new_household_barangay_id' => $tupas->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($tupas->id, Family::sole()->barangay_id);
        $this->assertSame(55, $entry->fresh()->toSyncPayload()['barangay_id']);
    }

    public function test_switching_a_pending_entry_to_new_family_gives_it_a_family_of_its_own_in_the_chosen_barangay(): void
    {
        [$event, $center, $bacong, $tupas] = $this->seedBase();
        $this->newFamily($event, $center, ['new_household_barangay_id' => $bacong->id]);
        $first = Family::sole();
        $this->post(route('ec-board-entries.store', $center), [
            'evacuation_event_id' => $event->id, 'sex' => 'male', 'age_bracket' => 'adult',
            'household_type' => 'existing', 'household_family_local_id' => (string) $first->id,
        ])->assertSessionHasNoErrors();
        $second = EcBoardEntry::latest('id')->first();

        $this->put(route('ec-board-entries.update', $second), [
            'evacuation_event_id' => $event->id, 'sex' => 'male', 'age_bracket' => 'adult',
            'household_type' => 'new', 'new_household_head_name' => 'Benito Villareal', 'head_is_self' => '1',
            'new_household_barangay_id' => $tupas->id,
        ])->assertSessionHasNoErrors();

        $second->refresh();
        $this->assertTrue($second->originated_household);
        $this->assertNotSame($first->id, $second->household_family_local_id);
        $this->assertSame($tupas->id, $second->household->barangay_id);
        $payload = $second->toSyncPayload();
        $this->assertSame(['new', 55, 'Benito Villareal'], [$payload['household_mode'], $payload['barangay_id'], $payload['family_name']]);
    }
}
