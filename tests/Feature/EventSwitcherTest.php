<?php

namespace Tests\Feature;

use App\Models\AppState;
use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Services\SelectedEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EventSwitcherTest extends TestCase
{
    use RefreshDatabase;

    private EvacuationEvent $active;

    private EvacuationEvent $watch;

    private EvacuationEvent $past;

    private EvacuationCenter $center;

    protected function setUp(): void
    {
        parent::setUp();
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $this->active = EvacuationEvent::create(['remote_id' => 79, 'name' => 'Typhoon Rolly', 'event_type' => 'typhoon', 'status' => 'active', 'start_date' => '2026-11-15']);
        $this->watch = EvacuationEvent::create(['remote_id' => 80, 'name' => 'Incoming Storm', 'event_type' => 'typhoon', 'status' => 'monitoring', 'start_date' => '2026-11-17']);
        $this->past = EvacuationEvent::create(['remote_id' => 70, 'name' => 'Old Typhoon', 'event_type' => 'typhoon', 'status' => 'closed', 'start_date' => '2026-10-01']);
        $this->center = EvacuationCenter::create(['remote_id' => 7, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'active']);
    }

    public function test_with_nothing_chosen_the_servers_rule_picks_an_active_event(): void
    {
        $this->assertSame($this->active->id, app(SelectedEvent::class)->get()->id);
        $this->get(route('dashboard'))->assertSee('data-event-switcher', false)
            ->assertSeeInOrder(['Event:', 'Typhoon Rolly', 'Open events', 'Incoming Storm', 'Closed events (read-only)', 'Old Typhoon']);
    }

    public function test_switching_tells_the_server_and_every_screen_follows(): void
    {
        Http::fake(['*/selected-event' => Http::response(['data' => ['selected' => ['id' => 80], 'chosen' => true]])]);

        $this->from(route('dashboard'))->post(route('selected-event.update'), ['event' => $this->watch->id])
            ->assertRedirect(route('dashboard'))->assertSessionHas('status', 'Now working on "Incoming Storm".');

        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['evacuation_event_id'] === 80);
        $this->get(route('evacuation-centers.ec-board', $this->center))->assertSee('&quot;Incoming Storm&quot; is being monitored. Registering evacuees opens when CSWD starts the evacuation.', false)
            ->assertDontSee('Saves to:');
    }

    public function test_a_choice_made_offline_is_sent_when_the_server_answers(): void
    {
        // Chosen while offline: kept on the device, not yet sent.
        app(SelectedEvent::class)->choose($this->past, null);
        $this->assertTrue(AppState::get('selected_event')['unsent']);
        $this->get(route('evacuation-centers.ec-board', $this->center))->assertSee('Closed: read-only');

        Http::fake(['*/selected-event' => Http::response(['data' => ['selected' => ['id' => 70], 'chosen' => true]]), '*/auth/me' => Http::response(['data' => []])]);
        $this->getJson(route('sync.probe'))->assertJson(['reachable' => true]);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['evacuation_event_id'] === 70);
        $this->assertFalse(AppState::get('selected_event')['unsent']);
    }

    public function test_refresh_takes_the_servers_selection(): void
    {
        Http::fake([
            '*/auth/me' => Http::response(['data' => []]),
            '*/barangays' => Http::response(['data' => [['id' => 4, 'name' => 'Bacong']]]),
            '*/evacuation-events' => Http::response(['data' => [
                ['id' => 79, 'name' => 'Typhoon Rolly', 'event_type' => 'typhoon', 'status' => 'active'],
                ['id' => 80, 'name' => 'Incoming Storm', 'event_type' => 'typhoon', 'status' => 'monitoring'],
            ]]),
            '*/selected-event' => Http::response(['data' => ['selected' => ['id' => 80, 'name' => 'Incoming Storm'], 'chosen' => true, 'open_count' => 2]]),
            '*/evacuation-centers?detail=1' => Http::response(['data' => []]),
            '*/puroks*' => Http::response(['data' => []]),
            '*/families-to-evacuate*' => Http::response(['data' => ['families' => []]]),
            '*/count-submissions*' => Http::response(['data' => []]),
            '*/box-relief/*' => Http::response(['data' => []]),
            '*/transfer-requests*' => Http::response(['data' => ['requests' => [], 'counts' => ['pending' => 0]]]),
            '*/private-house-families*' => Http::response(['data' => ['barangays' => []]]),
            '*/evacuation-centers/7/families*' => Http::response(['data' => []]),
            '*/returning-families*' => Http::response(['data' => ['past_events' => []]]),
        ]);

        $this->post(route('reference-data.refresh'))->assertSessionHasNoErrors();
        $this->assertSame($this->watch->id, app(SelectedEvent::class)->get()->id);
    }
}
