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

class NoOpenEventTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'No open event yet. CSWDO opens an event when a threat is announced.';

    private EvacuationCenter $center;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
        $this->barangay = Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $this->center = EvacuationCenter::create(['remote_id' => 7, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'active']);
    }

    private function add(EvacuationEvent $event)
    {
        return $this->post(route('ec-board-entries.store', $this->center), [
            'evacuation_event_id' => $event->id, 'sex' => 'female', 'age_bracket' => 'adult', 'household_type' => 'new',
            'new_household_barangay_id' => $this->barangay->id, 'new_household_head_name' => 'Reyes',
        ]);
    }

    public function test_with_no_open_event_the_ec_board_says_so_and_refuses_new_evacuees(): void
    {
        $closed = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Old', 'event_type' => 'typhoon', 'status' => 'closed']);

        $this->get(route('ec-board.index'))->assertSee(self::MESSAGE);
        $this->get(route('evacuation-centers.ec-board', $this->center))->assertSee(self::MESSAGE)->assertDontSee('class="btn btn-primary" data-open-board-modal', false);
        $this->add($closed)->assertSessionHasErrors(['evacuation_event_id' => self::MESSAGE]);
    }

    public function test_an_event_in_monitoring_takes_evacuees_and_turns_active_once_sent(): void
    {
        $monitoring = EvacuationEvent::create(['remote_id' => 2, 'name' => 'Typhoon watch', 'event_type' => 'typhoon', 'status' => 'monitoring']);

        $this->get(route('evacuation-centers.ec-board', $this->center))->assertDontSee(self::MESSAGE)->assertSee('class="btn btn-primary" data-open-board-modal', false);
        $this->add($monitoring)->assertSessionHasNoErrors();

        Http::fake(['*/evacuees' => Http::response(['data' => ['id' => 50], 'evacuee_id' => 900], 201)]);
        $this->postJson(route('sync.step'))->assertJson(['status' => 'sent']);

        $this->assertSame('active', $monitoring->fresh()->status);
        $this->assertNotNull(EcBoardEntry::first()->synced_at);
    }
}
