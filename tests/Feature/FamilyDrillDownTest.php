<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\Evacuee;
use App\Models\Family;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamilyDrillDownTest extends TestCase
{
    use RefreshDatabase;

    private function login(?int $barangayRemoteId = null): void
    {
        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official',
            'barangay_id' => $barangayRemoteId, 'barangay_name' => null,
            'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);
    }

    private function seedFamily(Barangay $barangay, EvacuationEvent $event, ?EvacuationCenter $center, string $headFirstName = 'Juan', string $headLastName = 'Dela Cruz'): Family
    {
        $family = Family::create([
            'barangay_id' => $barangay->id,
            'evacuation_event_id' => $event->id,
            'evacuation_center_id' => $center?->id,
            'displacement_type' => $center ? 'inside_center' : 'outside_center',
        ]);

        Evacuee::create([
            'family_id' => $family->id,
            'first_name' => $headFirstName, 'last_name' => $headLastName,
            'sex' => 'male', 'date_of_birth' => '1990-01-01', 'is_head_of_family' => true,
        ]);

        return $family;
    }

    public function test_default_view_shows_barangay_level_summary_with_family_counts(): void
    {
        $this->login();
        $barangayA = Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $barangayB = Barangay::create(['remote_id' => 2, 'name' => 'Barangay B']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);

        $this->seedFamily($barangayA, $event, null);
        $this->seedFamily($barangayA, $event, null, 'Maria', 'Santos');
        $this->seedFamily($barangayB, $event, null, 'Pedro', 'Reyes');

        $page = $this->get(route('families.index'));

        $page->assertOk();
        $page->assertSee('All barangays');
        $page->assertSee('Barangay A');
        $page->assertSee('2 families');
        $page->assertSee('Barangay B');
        $page->assertSee('1 family');
        // Level 3 content (individual family cards) must not leak into the
        // landing view.
        $page->assertDontSee('Waiting to sync');
    }

    public function test_drilling_into_a_barangay_shows_center_level_summary_including_outside_center_bucket(): void
    {
        $this->login();
        $barangay = Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Barangay Hall', 'status' => 'active']);

        $this->seedFamily($barangay, $event, $center);
        $this->seedFamily($barangay, $event, null, 'Maria', 'Santos');

        $page = $this->get(route('families.index', ['barangay' => $barangay->id]));

        $page->assertOk();
        $page->assertSeeInOrder(['All barangays', 'Barangay A']);
        $page->assertSee('Barangay Hall');
        $page->assertSee('Outside center / unassigned');
    }

    public function test_drilling_into_a_center_shows_the_scoped_family_list(): void
    {
        $this->login();
        $barangay = Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Barangay Hall', 'status' => 'active']);
        $otherCenter = EvacuationCenter::create(['remote_id' => 2, 'barangay_remote_id' => 1, 'name' => 'Covered Court', 'status' => 'active']);

        $this->seedFamily($barangay, $event, $center, 'Juan', 'Dela Cruz');
        $this->seedFamily($barangay, $event, $otherCenter, 'Maria', 'Santos');

        $page = $this->get(route('families.index', ['barangay' => $barangay->id, 'center' => $center->id]));

        $page->assertOk();
        $page->assertSeeInOrder(['All barangays', 'Barangay A', 'Barangay Hall']);
        $page->assertSee('Waiting to sync');
        // Scoped correctly: this family (at Covered Court) must not appear
        // under Barangay Hall's list.
        $page->assertDontSee('Covered Court');
    }

    public function test_drilling_into_the_outside_center_bucket_shows_only_unassigned_families(): void
    {
        $this->login();
        $barangay = Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Barangay Hall', 'status' => 'active']);

        $insideFamily = $this->seedFamily($barangay, $event, $center, 'Juan', 'Dela Cruz');
        $outsideFamily = $this->seedFamily($barangay, $event, null, 'Maria', 'Santos');

        $page = $this->get(route('families.index', ['barangay' => $barangay->id, 'center' => 'none']));

        $page->assertOk();
        $page->assertSee('Outside center / unassigned');
        // The inside-center family must not leak into this bucket -- its
        // own card would show its center's name as the displacement label
        // (see _family_cards.blade.php), which is absent here.
        $page->assertDontSee('Barangay Hall');
    }

    public function test_search_finds_a_family_by_member_name_regardless_of_drill_down_level(): void
    {
        $this->login();
        $barangayA = Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $barangayB = Barangay::create(['remote_id' => 2, 'name' => 'Barangay B']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 2, 'name' => 'Covered Court', 'status' => 'active']);

        $this->seedFamily($barangayA, $event, null, 'Juan', 'Dela Cruz');
        // Deliberately in a DIFFERENT barangay+center than the one above --
        // search must find this regardless, with no need to have drilled
        // into Barangay B / Covered Court first.
        $this->seedFamily($barangayB, $event, $center, 'Maria', 'Santos');

        $response = $this->get(route('families.index', ['search' => 'Santos']));

        $response->assertOk();
        $response->assertSee('1 result(s)');
        $response->assertSee('Barangay B');
        $response->assertDontSee('Barangay A');
    }

    public function test_register_form_defaults_barangay_to_the_logged_in_staffs_own_barangay(): void
    {
        // barangay_id on LocalAuth is the CENTRAL server's remote barangay
        // id (set from the login response) -- matched here against
        // Barangay::remote_id, not this cache table's own local id.
        $this->login(barangayRemoteId: 2);
        Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $ownBarangay = Barangay::create(['remote_id' => 2, 'name' => 'Barangay B']);
        EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);

        $response = $this->get(route('families.create'));

        $response->assertOk();
        $response->assertSee('data-remote-id="2" selected', false);
        $response->assertDontSee('data-remote-id="1" selected', false);
    }

    public function test_register_form_defaults_to_blank_barangay_when_staff_has_none(): void
    {
        $this->login(barangayRemoteId: null);
        Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        Barangay::create(['remote_id' => 2, 'name' => 'Barangay B']);
        EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);

        $response = $this->get(route('families.create'));

        $response->assertOk();
        $response->assertDontSee('data-remote-id="1" selected', false);
        $response->assertDontSee('data-remote-id="2" selected', false);
    }

    // -----------------------------------------------------------------
    // Part 1: pending EC Board entries visible in Registered Families
    // -----------------------------------------------------------------

    public function test_barangay_level_shows_a_pending_ec_board_count_badge(): void
    {
        $this->login();
        $barangay = Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Barangay Hall', 'status' => 'active']);

        // No Family record at all for this evacuee -- it lives only in
        // ec_board_entries, which is exactly the visibility gap Part 1
        // fixes. A real family in the same barangay is also seeded so the
        // badge renders alongside a normal family count, not instead of it.
        $this->seedFamily($barangay, $event, null, 'Existing', 'Family');
        \App\Models\EcBoardEntry::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'sex' => 'male', 'age_bracket' => 'adult',
            'new_household_head_name' => 'EC Board Only Evacuee',
        ]);

        $page = $this->get(route('families.index'));

        $page->assertOk();
        $page->assertSee('1 EC Board pending');
    }

    public function test_center_level_pending_ec_board_badge_links_directly_to_that_centers_ec_board_page(): void
    {
        $this->login();
        $barangay = Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Barangay Hall', 'status' => 'active']);

        $this->seedFamily($barangay, $event, $center);
        \App\Models\EcBoardEntry::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'sex' => 'female', 'age_bracket' => 'teenage',
            'new_household_head_name' => 'EC Board Only Evacuee',
        ]);

        $page = $this->get(route('families.index', ['barangay' => $barangay->id]));

        $page->assertOk();
        $page->assertSee('1 EC Board pending');
        $page->assertSee(route('evacuation-centers.ec-board', $center), false);
    }

    public function test_a_synced_ec_board_entry_does_not_count_toward_the_pending_badge(): void
    {
        $this->login();
        $barangay = Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Barangay Hall', 'status' => 'active']);

        $this->seedFamily($barangay, $event, null);
        \App\Models\EcBoardEntry::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'sex' => 'male', 'age_bracket' => 'adult',
            'new_household_head_name' => 'Already Synced',
            'synced_at' => now(), 'remote_id' => 5,
        ]);

        $page = $this->get(route('families.index'));

        $page->assertOk();
        $page->assertDontSee('EC Board pending');
    }

    // -----------------------------------------------------------------
    // Part 4: Dashboard cleanup -- EC Board is now the sidebar's primary
    // entry point, not a Dashboard button; Register-a-family duplication
    // removed from the Dashboard entirely (still reachable from the
    // Registered Families page).
    // -----------------------------------------------------------------

    public function test_dashboard_no_longer_shows_go_to_ec_board_or_register_a_family(): void
    {
        $this->login();

        $page = $this->get(route('dashboard'));

        $page->assertOk();
        $page->assertDontSee('Go to EC Board');
        $page->assertDontSee('Register a family');
    }

    public function test_dashboard_still_shows_refresh_and_view_registered_families(): void
    {
        $this->login();

        $page = $this->get(route('dashboard'));

        $page->assertOk();
        $page->assertSee('Refresh reference data');
        $page->assertSee('View registered families');
    }

    public function test_register_a_family_remains_fully_functional_from_the_families_page(): void
    {
        $this->login();
        $barangay = Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);

        $response = $this->post(route('families.store'), [
            'barangay_id' => $barangay->id,
            'evacuation_event_id' => $event->id,
            'displacement_type' => 'outside_center',
            'members' => [
                ['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'sex' => 'male', 'date_of_birth' => '1990-01-01', 'is_head_of_family' => true],
            ],
        ]);

        $response->assertRedirect(route('families.index'));
        $this->assertDatabaseHas('families', ['barangay_id' => $barangay->id]);
    }

    // -----------------------------------------------------------------
    // Part 5: Evacuation Centers list grouped by barangay
    // -----------------------------------------------------------------

    public function test_evacuation_centers_list_is_grouped_by_barangay(): void
    {
        $this->login();
        Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        Barangay::create(['remote_id' => 2, 'name' => 'Barangay B']);
        EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Center One', 'status' => 'active']);
        EvacuationCenter::create(['remote_id' => 2, 'barangay_remote_id' => 1, 'name' => 'Center Two', 'status' => 'active']);
        EvacuationCenter::create(['remote_id' => 3, 'barangay_remote_id' => 2, 'name' => 'Center Three', 'status' => 'active']);

        $page = $this->get(route('evacuation-centers.index'));

        $page->assertOk();
        // Both barangay headings render, and each center appears after
        // its OWN barangay's heading, not just anywhere on the page.
        $page->assertSeeInOrder(['Barangay A', 'Center One', 'Center Two', 'Barangay B', 'Center Three']);
    }
}
