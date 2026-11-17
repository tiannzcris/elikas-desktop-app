<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EcBoardCardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cards_show_occupancy_over_capacity_and_a_summary_first(): void
    {
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'barangay_name' => 'Bacong', 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon', 'event_type' => 'typhoon', 'status' => 'active']);
        $school = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 4, 'name' => 'School', 'status' => 'on_standby', 'capacity_persons' => 120, 'current_occupancy' => 47]);
        EvacuationCenter::create(['remote_id' => 2, 'barangay_remote_id' => 4, 'name' => 'Court', 'status' => 'on_standby', 'capacity_persons' => 200, 'current_occupancy' => 0]);
        EcBoardEntry::create(['evacuation_center_id' => $school->id, 'evacuation_event_id' => $event->id, 'sex' => 'male', 'age_bracket' => 'adult']);

        $this->get(route('ec-board.index'))->assertOk()
            ->assertSeeInOrder(['Centers in use', '1', 'of 2', 'People inside', '48', '/ 320', 'Full', '0', 'Waiting to sync', '1'])
            ->assertSee('48 / 120')->assertSee('40% full')
            ->assertSee('0 / 200')
            ->assertSee('data-center-status="active"', false)->assertSee('data-center-status="on_standby"', false)
            ->assertSee('1 person waiting to sync');
    }
}
