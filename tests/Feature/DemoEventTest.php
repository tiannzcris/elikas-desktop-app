<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DemoEventTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): void
    {
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
    }

    public function test_refresh_stores_is_demo_and_start_date(): void
    {
        $this->signIn();
        Http::fake([
            '*/auth/me' => Http::response(['data' => []]),
            '*/barangays' => Http::response(['data' => [['id' => 4, 'name' => 'Bacong']]]),
            '*/evacuation-events' => Http::response(['data' => [
                ['id' => 9, 'name' => 'DEMO - Typhoon Drill', 'event_type' => 'typhoon', 'status' => 'active', 'is_demo' => true, 'start_date' => '2026-11-10'],
            ]]),
            '*/evacuation-centers?detail=1*' => Http::response(['data' => []]),
        ]);

        $this->post(route('reference-data.refresh'));

        $event = EvacuationEvent::firstWhere('remote_id', 9);
        $this->assertTrue($event->is_demo);
        $this->assertSame('2026-11-10', $event->start_date->toDateString());
    }

    public function test_a_practice_event_looks_like_any_other_event(): void
    {
        $this->signIn();
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 4, 'name' => 'Bacong Gym', 'status' => 'active']);
        $demo = EvacuationEvent::create(['remote_id' => 9, 'name' => 'Typhoon Drill', 'event_type' => 'typhoon', 'status' => 'active', 'is_demo' => true]);
        $this->selectEvent($demo);

        // No DEMO badge, label or prefix anywhere: board, switcher, Saves to.
        $this->get(route('evacuation-centers.ec-board', ['center' => $center]))
            ->assertOk()->assertSee('Typhoon Drill')->assertDontSee('data-demo-badge', false)->assertDontSee('DEMO');
        $this->assertSame('Typhoon Drill', $demo->optionLabel());
    }
}
