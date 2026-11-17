<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CenterStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
    }

    public function test_refresh_keeps_capacity_occupancy_and_the_status_shown(): void
    {
        Http::fake([
            '*/auth/me' => Http::response(['data' => []]),
            '*/barangays' => Http::response(['data' => [['id' => 4, 'name' => 'Bacong']]]),
            '*/evacuation-events' => Http::response(['data' => []]),
            '*/evacuation-centers?detail=1' => Http::response(['data' => [['id' => 7, 'barangay' => ['id' => 4, 'name' => 'Bacong'],
                'name' => 'Gym', 'status' => 'on_standby', 'effective_status' => 'active', 'capacity_persons' => 120, 'current_occupancy' => 48]]]),
            '*/puroks*' => Http::response(['data' => []]),
        ]);

        $this->post(route('reference-data.refresh'))->assertSessionHasNoErrors();

        $center = EvacuationCenter::first();
        $this->assertSame([4, 120, 48, 'active'], [(int) $center->barangay_remote_id, $center->capacity_persons, $center->current_occupancy, $center->displayStatus()]);
    }

    public function test_the_status_follows_the_servers_rule_and_the_web_colors(): void
    {
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon', 'event_type' => 'typhoon', 'status' => 'active']);
        $standby = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 4, 'name' => 'Empty Hall', 'status' => 'on_standby', 'capacity_persons' => 100]);
        $nearlyFull = EvacuationCenter::create(['remote_id' => 2, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'on_standby', 'capacity_persons' => 10, 'current_occupancy' => 8]);
        $closed = EvacuationCenter::create(['remote_id' => 3, 'barangay_remote_id' => 4, 'name' => 'Chapel', 'status' => 'closed']);

        $this->assertSame('on_standby', $standby->displayStatus());
        $this->assertSame('active', $nearlyFull->displayStatus());
        // One more arrival on this device makes it 90%: full.
        EcBoardEntry::create(['evacuation_center_id' => $nearlyFull->id, 'evacuation_event_id' => $event->id, 'sex' => 'male', 'age_bracket' => 'adult']);
        $this->assertSame('full', $nearlyFull->displayStatus());
        $this->assertSame('closed', $closed->displayStatus());

        $this->get(route('ec-board.index'))->assertOk()
            ->assertSee('data-center-status="on_standby"', false)->assertSee('badge-info', false)
            ->assertSee('data-center-status="full"', false)->assertSee('badge-danger', false);
    }
}
