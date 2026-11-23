<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 2.1.0 part 1: what the November 22-23 server sends, read correctly. */
class CompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
    }

    public function test_a_nearly_full_center_says_so_and_full_still_wins_at_90_percent(): void
    {
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'active',
            'capacity_persons' => 100, 'capacity_source' => 'official', 'current_occupancy' => 80, 'effective_status' => 'nearly_full']);
        $this->assertSame('nearly_full', $center->displayStatus());
        $this->assertTrue($center->inUse());
        $this->assertFalse($center->needsSpace());

        $center->update(['current_occupancy' => 92]);
        $this->assertSame('full', $center->displayStatus());
        $this->assertTrue($center->needsSpace());

        // An unknown capacity is never nearly full.
        $center->update(['capacity_persons' => null, 'capacity_source' => 'unknown', 'current_occupancy' => 50]);
        $this->assertSame('active', $center->displayStatus());
    }

    public function test_the_center_card_shows_nearly_full(): void
    {
        $this->signIn();
        EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'active',
            'capacity_persons' => 100, 'capacity_source' => 'official', 'current_occupancy' => 80, 'effective_status' => 'nearly_full']);

        $this->get(route('ec-board.index'))->assertSee('data-center-status="nearly_full"', false)->assertSee('Nearly full');
    }

    public function test_a_lock_refusal_shows_the_lock_design_not_an_error(): void
    {
        $this->signIn();
        EvacuationEvent::create(['remote_id' => 9, 'name' => 'Incoming Typhoon', 'event_type' => 'typhoon', 'status' => 'monitoring']);
        $lock = '"Incoming Typhoon" is being monitored. Transfers opens when CSWD starts the evacuation.';

        $this->withSession(['errors' => (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag(['x' => $lock]))])
            ->get(route('dashboard'))
            ->assertSee('data-lock-notice="monitoring"', false)->assertSee('Waiting for the evacuation to start')
            ->assertDontSee('callout callout-danger', false);
    }
}
