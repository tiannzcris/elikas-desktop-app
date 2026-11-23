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

    public function test_a_monitoring_lock_answer_waits_and_goes_by_itself_once_the_event_is_active(): void
    {
        // Saved while this device still thought the event was active.
        $event = EvacuationEvent::create(['remote_id' => 2, 'name' => 'Typhoon watch', 'event_type' => 'typhoon', 'status' => 'active']);
        $this->add($event)->assertSessionHasNoErrors();

        $lock = '"Typhoon watch" is being monitored. Registering evacuees opens when CSWD starts the evacuation.';
        Http::fake(['*/evacuees' => Http::sequence()
            ->push(['message' => $lock, 'errors' => ['evacuation_event_id' => [$lock]]], 422)
            ->push(['data' => ['id' => 50], 'evacuee_id' => 900], 201)]);

        $this->postJson(route('sync.step'))->assertJson(['status' => 'event_locked']);
        $this->assertSame('event_locked', EcBoardEntry::first()->sync_state);
        $this->assertSame('monitoring', $event->fresh()->status);
        $this->get(route('sync.index'))->assertSee('Waiting for the evacuation')->assertSee($lock)
            ->assertSee('It goes by itself once CSWD starts the evacuation.');

        // Nothing goes while the event is still monitored...
        $this->postJson(route('sync.step'))->assertJson(['status' => 'empty']);
        // ...and it goes by itself once the event is active (CSWD started the evacuation).
        $event->refresh()->update(['status' => 'active']);
        $this->postJson(route('sync.step'))->assertJson(['status' => 'sent']);
        $this->assertNotNull(EcBoardEntry::first()->synced_at);
        // A sent arrival never switches an event's status here any more.
        $this->assertSame('active', $event->fresh()->status);
    }
}
