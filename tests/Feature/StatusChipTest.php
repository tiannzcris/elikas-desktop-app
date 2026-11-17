<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusChipTest extends TestCase
{
    use RefreshDatabase;

    public function test_officials_get_one_chip_with_the_queue_and_the_board_says_as_of(): void
    {
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 7, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'active']);
        EcBoardEntry::create(['evacuation_center_id' => $center->id, 'evacuation_event_id' => $event->id, 'sex' => 'male', 'age_bracket' => 'adult']);

        $page = $this->get(route('evacuation-centers.ec-board', $center))->assertOk();
        $page->assertSee('id="sync-chip"', false)->assertSee('1 record waiting')
            ->assertDontSee('id="connection-badge"', false)
            ->assertSee('data-as-of', false)->assertSee('As of never fetched');
    }

    public function test_cswd_keeps_the_connection_chip(): void
    {
        LocalAuth::create(['remote_user_id' => 2, 'name' => 'C', 'email' => 'c@x', 'role' => 'cswd_personnel',
            'permissions' => ['operations.access', 'scope.all_barangays', 'page.monitoring'], 'api_token' => 't', 'logged_in_at' => now()]);

        $this->get(route('dashboard'))->assertSee('id="connection-badge"', false)->assertDontSee('id="sync-chip"', false);
    }
}
