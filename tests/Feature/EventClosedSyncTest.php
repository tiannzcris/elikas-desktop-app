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

/** "No open event" and "event closed" answers wait on the Sync page; they are never sent again by themselves. */
class EventClosedSyncTest extends TestCase
{
    use RefreshDatabase;

    private EvacuationEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $this->event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon Amang', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 7, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'active']);
        EcBoardEntry::create(['evacuation_center_id' => $center->id, 'evacuation_event_id' => $this->event->id, 'sex' => 'male', 'age_bracket' => 'adult',
            'new_household_head_name' => 'Reyes']);
    }

    public static function answers(): array
    {
        return [
            'closed' => ['"Typhoon Amang" is closed. A closed event is read-only.'],
            'none open' => ['No open event yet. CSWDO opens an event when a threat is announced.'],
        ];
    }

    /** @dataProvider answers */
    public function test_the_answer_needs_attention_and_is_not_sent_again_by_sync_now(string $message): void
    {
        Http::fake(['*/evacuees' => Http::response(['success' => false, 'message' => $message,
            'errors' => ['evacuation_event_id' => [$message]]], 422)]);

        $this->postJson(route('sync.step'))->assertJson(['status' => 'event_closed']);
        $this->assertNull(EcBoardEntry::first()->synced_at);
        $this->assertTrue($this->event->fresh()->isClosed(), 'the device learns the event is closed');

        // Sync now again: nothing is sent for it.
        $this->post(route('sync.all'))->assertSessionHas('status', 'Nothing to send right now.');
        Http::assertSentCount(1);

        $this->get(route('sync.index'))->assertOk()->assertSee('Event closed')->assertSee($message)->assertSee('Try again');
    }
}
