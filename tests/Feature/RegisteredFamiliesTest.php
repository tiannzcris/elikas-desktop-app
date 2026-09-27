<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\Evacuee;
use App\Models\Family;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Registered families is the app's only household listing: this device's
 * own records, every barangay, split into "Not yet synced" and "Synced".
 * The online-roster Evacuees page is gone -- viewing the server's roster
 * is the web dashboard's job.
 */
class RegisteredFamiliesTest extends TestCase
{
    use RefreshDatabase;

    private function login(?int $barangayRemoteId = 2, ?string $barangayName = 'Zamora'): void
    {
        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official',
            'barangay_id' => $barangayRemoteId, 'barangay_name' => $barangayName,
            'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);
    }

    private function family(Barangay $barangay, EvacuationEvent $event, string $headName, bool $synced, ?EvacuationCenter $center = null): Family
    {
        static $remoteId = 500;

        $family = Family::create([
            'barangay_id' => $barangay->id,
            'evacuation_event_id' => $event->id,
            'evacuation_center_id' => $center?->id,
            'displacement_type' => $center ? 'inside_center' : 'outside_center',
            'remote_id' => $synced ? ++$remoteId : null,
            'synced_at' => $synced ? now() : null,
        ]);

        [$first, $last] = explode(' ', $headName, 2);
        Evacuee::create([
            'family_id' => $family->id, 'first_name' => $first, 'last_name' => $last,
            'sex' => 'male', 'date_of_birth' => '1990-01-01', 'is_head_of_family' => true,
        ]);

        return $family;
    }

    /** @return array{0: Barangay, 1: Barangay, 2: EvacuationEvent, 3: EvacuationCenter} */
    private function seedReference(): array
    {
        $abella = Barangay::create(['remote_id' => 1, 'name' => 'Abella']);
        $zamora = Barangay::create(['remote_id' => 2, 'name' => 'Zamora']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 2, 'name' => 'Zamora Covered Court', 'status' => 'active']);

        return [$abella, $zamora, $event, $center];
    }

    // -----------------------------------------------------------------
    // The online-roster Evacuees page is gone
    // -----------------------------------------------------------------

    public function test_the_evacuees_page_its_route_and_its_nav_entry_are_gone(): void
    {
        $this->login();

        $this->assertFalse(Route::has('evacuees.index'));
        $this->get('/evacuees')->assertNotFound();

        $page = $this->get(route('dashboard'));
        $page->assertOk();
        $page->assertDontSee('> Evacuees', false);
        $page->assertDontSee('All Evacuees');
        $page->assertSee('> Registered families', false);
    }

    public function test_the_registration_forms_duplicate_warning_matches_this_devices_own_households(): void
    {
        $this->login();
        [$abella, $zamora, $event] = $this->seedReference();
        $this->family($abella, $event, 'Maria Santos', synced: true);
        $editing = $this->family($zamora, $event, 'Pedro Reyes', synced: false);

        $form = $this->get(route('families.create'));
        $form->assertOk();
        $form->assertSee('"knownHouseholds":[{"head_name":"Maria Santos","barangay_name":"Abella"},{"head_name":"Pedro Reyes","barangay_name":"Zamora"}]', false);
        $form->assertSee('"familiesSearchUrl":"'.str_replace('/', '\/', route('families.index')).'"', false);

        // Editing a family never warns about itself.
        $edit = $this->get(route('families.edit', $editing));
        $edit->assertSee('"knownHouseholds":[{"head_name":"Maria Santos","barangay_name":"Abella"}]', false);
    }

    // -----------------------------------------------------------------
    // Registered families: every barangay, split by sync state
    // -----------------------------------------------------------------

    public function test_registered_families_lists_every_barangay_split_into_not_yet_synced_and_synced(): void
    {
        $this->login();
        [$abella, $zamora, $event] = $this->seedReference();
        $this->family($zamora, $event, 'Pending Own', synced: false);
        $this->family($abella, $event, 'Pending Other', synced: false);
        $this->family($abella, $event, 'Synced Other', synced: true);
        $this->family($zamora, $event, 'Synced Own', synced: true);

        $page = $this->get(route('families.index'));

        $page->assertOk();
        // Pending cards, own barangay first, all above the Synced section.
        $page->assertSeeInOrder(['Not yet synced', 'Zamora', 'Pending Own', 'Abella', 'Pending Other', 'Synced', 'Zamora', '1 synced family', 'Abella', '1 synced family']);
        // Synced families are counted in the drill-down, not listed as cards here.
        $page->assertDontSee('Synced Own');
        $page->assertDontSee('Synced Other');

        // The drill-down lists synced families only.
        $drill = $this->get(route('families.index', ['barangay' => $abella->id, 'center' => 'none']));
        $drill->assertSee('Synced Other');
        $drill->assertDontSee('Pending Other');
    }

    public function test_the_not_yet_synced_section_says_so_when_nothing_is_waiting(): void
    {
        $this->login();
        [, $zamora, $event] = $this->seedReference();
        $this->family($zamora, $event, 'Synced Own', synced: true);

        $page = $this->get(route('families.index'));

        $page->assertOk();
        $page->assertSee('every family on this device has synced');
    }

    public function test_search_results_are_split_the_same_way(): void
    {
        $this->login();
        [$abella, $zamora, $event] = $this->seedReference();
        $this->family($zamora, $event, 'Maria Santos', synced: true);
        $this->family($abella, $event, 'Rosa Santos', synced: false);

        $page = $this->get(route('families.index', ['search' => 'Santos']));

        $page->assertOk();
        $page->assertSeeInOrder(['Not yet synced', 'Rosa Santos', 'Synced', 'Maria Santos']);
    }

    public function test_register_a_family_is_gone_from_the_page_but_its_route_still_works(): void
    {
        $this->login();
        $this->seedReference();

        $page = $this->get(route('families.index'));
        $page->assertOk();
        $page->assertDontSee('Register a family');
        $page->assertDontSee(route('families.create'), false);

        $form = $this->get(route('families.create'));
        $form->assertOk();
        $form->assertSee('Save family (offline)');
    }

    public function test_an_offline_ec_board_evacuee_shows_under_not_yet_synced_until_it_syncs(): void
    {
        $this->login();
        [, $zamora, $event, $center] = $this->seedReference();

        $this->post(route('ec-board-entries.store', $center), [
            'evacuation_event_id' => $event->id,
            'sex' => 'male',
            'age_bracket' => 'adult',
            'household_type' => 'new',
            'new_household_head_name' => 'Juan Dela Cruz',
            'head_is_self' => '1',
        ])->assertSessionHasNoErrors();
        $family = Family::sole();

        $page = $this->get(route('families.index'));
        $page->assertOk();
        $page->assertSeeInOrder(['Not yet synced', '1 person', 'Zamora Covered Court', 'Zamora', 'Juan Dela Cruz', 'Manage on EC Board', 'Synced']);
        // Its card sends staff to the EC Board -- the registration form
        // can't edit a household that has no full registration behind it.
        $page->assertDontSee(route('families.edit', $family), false);
        $page->assertDontSee(route('families.destroy', $family), false);

        Http::fake(['*/evacuation-centers/*/evacuees' => Http::response(['data' => ['id' => 700], 'evacuee_id' => 900], 201)]);
        $this->post(route('families.sync'))->assertSessionHas('status', '1 record(s) synced successfully.');

        $after = $this->get(route('families.index'));
        $after->assertSee('every family on this device has synced');
        $after->assertSeeInOrder(['Synced', 'Zamora', '1 synced family']);
        $after->assertDontSee('Juan Dela Cruz');
    }

    public function test_an_ec_board_household_cannot_be_edited_or_deleted_through_the_registration_routes(): void
    {
        $this->login();
        [, , $event, $center] = $this->seedReference();
        $this->post(route('ec-board-entries.store', $center), [
            'evacuation_event_id' => $event->id,
            'sex' => 'female',
            'age_bracket' => 'adult',
            'household_type' => 'new',
            'new_household_head_name' => 'Rosa Santos',
            'head_is_self' => '1',
        ]);
        $family = Family::sole();

        $message = 'This household was added on the EC Board -- edit or remove it from its EC Board entries.';
        $this->get(route('families.edit', $family))->assertRedirect(route('families.index'))->assertSessionHas('status', $message);
        $this->delete(route('families.destroy', $family))->assertRedirect(route('families.index'))->assertSessionHas('status', $message);
        $this->assertModelExists($family);
    }
}
