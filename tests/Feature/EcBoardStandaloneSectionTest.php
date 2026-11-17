<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** EC Board opens on the official's own barangay's open centers. */
class EcBoardStandaloneSectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_ec_board_lists_only_the_own_barangays_open_centers(): void
    {
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'barangay_name' => 'Bacong', 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $gym = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 4, 'name' => 'Bacong Gym', 'status' => 'active']);
        EvacuationCenter::create(['remote_id' => 2, 'barangay_remote_id' => 4, 'name' => 'Old Chapel', 'status' => 'closed']);
        EvacuationCenter::create(['remote_id' => 3, 'barangay_remote_id' => 9, 'name' => 'Elsewhere Hall', 'status' => 'active']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon', 'event_type' => 'typhoon', 'status' => 'active']);
        EcBoardEntry::create(['evacuation_center_id' => $gym->id, 'evacuation_event_id' => $event->id, 'sex' => 'male', 'age_bracket' => 'adult']);

        $this->get(route('ec-board.index'))->assertOk()
            ->assertSee('Barangay Bacong')
            ->assertSee('Bacong Gym')->assertSee(route('evacuation-centers.ec-board', $gym), false)
            ->assertSee('1 waiting to sync')
            ->assertDontSee('Old Chapel')->assertDontSee('Elsewhere Hall');
    }

    public function test_the_board_links_back_to_the_ec_board(): void
    {
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $gym = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 4, 'name' => 'Bacong Gym', 'status' => 'active']);

        $this->get(route('evacuation-centers.ec-board', $gym))->assertOk()
            ->assertSee(route('ec-board.index'), false)
            ->assertSee('No open event yet. CSWDO opens an event when a threat is announced.');
    }
}
