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
}
