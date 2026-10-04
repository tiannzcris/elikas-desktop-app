<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\Family;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Add evacuee's "Already here" offers the families here right now, the
 * same list as the web dashboard: another barangay's family is labelled
 * "Family #N · <barangay> · X here" (the central server sends it without
 * a name), and this device's own families are those of the board's event.
 */
class EcBoardAlreadyHereTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: EvacuationEvent, 1: EvacuationEvent, 2: EvacuationCenter, 3: Barangay} */
    private function seedBase(): array
    {
        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official',
            'barangay_id' => 4, 'barangay_name' => 'Bacong', 'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);
        $bacong = Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $amang = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Tropical Storm Amang', 'event_type' => 'typhoon', 'status' => 'active']);
        $mayon = EvacuationEvent::create(['remote_id' => 3, 'name' => 'Mayon Alert Level 2', 'event_type' => 'volcanic', 'status' => 'monitoring']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 4, 'name' => 'Bacong Gym', 'status' => 'active']);

        return [$amang, $mayon, $center, $bacong];
    }

    public function test_another_barangays_family_is_labelled_by_number_barangay_and_how_many_are_here(): void
    {
        [$amang, , $center] = $this->seedBase();

        Http::fake(['*/evacuation-centers/1/families*' => Http::response(['data' => [
            ['id' => 2, 'name' => 'Reyes', 'head_of_family' => ['id' => 9, 'full_name' => 'Ana Reyes'], 'member_count' => 4],
            ['id' => 16, 'is_generic' => true, 'barangay' => ['id' => 55, 'name' => 'Tupas'], 'here_count' => 2, 'member_count' => 3, 'has_head_linked' => true, 'name' => null, 'head_of_family' => null, 'home_address' => null],
            ['id' => 17, 'is_generic' => true, 'barangay' => ['id' => 55, 'name' => 'Tupas'], 'here_count' => 1, 'member_count' => 1, 'has_head_linked' => false, 'name' => null, 'head_of_family' => null, 'home_address' => null],
        ]])]);

        $rows = collect($this->get(route('evacuation-centers.households-refresh', $center).'?event='.$amang->id)->assertOk()->json('households'))->keyBy('value');

        $this->assertSame('Reyes', $rows['remote-2']['label']);
        $this->assertSame('Family #16 · Tupas · 2 here', $rows['remote-16']['label']);
        $this->assertSame('Family #17 · Tupas · 1 here', $rows['remote-17']['label']);
        // Whether "This person is the family head" is offered: from
        // has_head_linked, since such a family has no head_of_family.
        $this->assertTrue($rows['remote-16']['head_linked']);
        $this->assertFalse($rows['remote-17']['head_linked']);
    }

    public function test_the_server_list_says_which_synced_families_are_still_here(): void
    {
        [$amang, , $center, $bacong] = $this->seedBase();
        foreach ([[30, 'Still Here'], [31, 'Left Already']] as [$remoteId, $name]) {
            Family::create(['barangay_id' => $bacong->id, 'evacuation_event_id' => $amang->id, 'evacuation_center_id' => $center->id,
                'displacement_type' => 'inside_center', 'name' => $name, 'remote_id' => $remoteId, 'synced_at' => now()]);
        }
        Http::fake(['*/evacuation-centers/1/families*' => Http::response(['data' => [['id' => 30, 'name' => 'Still Here', 'head_of_family' => null]]])]);

        $response = $this->get(route('evacuation-centers.households-refresh', $center).'?event='.$amang->id)->assertOk();

        $response->assertJsonPath('households', []);
        $response->assertJsonPath('here_remote_ids', [30]);
        // The page marks each local option with its remote id, so the form
        // can drop "Left Already" once that list arrives.
        $page = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $amang->id]));
        $page->assertSee('data-remote-id="30"', false);
        $page->assertSee('data-remote-id="31"', false);
    }

    public function test_only_this_devices_families_of_the_boards_event_are_offered(): void
    {
        [$amang, $mayon, $center, $bacong] = $this->seedBase();
        Family::create(['barangay_id' => $bacong->id, 'evacuation_event_id' => $amang->id, 'evacuation_center_id' => $center->id, 'displacement_type' => 'inside_center', 'name' => 'Amang Family', 'created_via_ec_board' => true]);
        Family::create(['barangay_id' => $bacong->id, 'evacuation_event_id' => $mayon->id, 'evacuation_center_id' => $center->id, 'displacement_type' => 'inside_center', 'name' => 'Mayon Family', 'created_via_ec_board' => true]);

        $amangBoard = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $amang->id]));
        $amangBoard->assertSee('Amang Family');
        $amangBoard->assertDontSee('Mayon Family');

        $mayonBoard = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $mayon->id]));
        $mayonBoard->assertSee('Mayon Family');
        $mayonBoard->assertDontSee('Amang Family');
    }

    public function test_no_families_here_says_so(): void
    {
        [$amang, , $center] = $this->seedBase();

        $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $amang->id]))
            ->assertSee('No families here right now.');
    }
}
