<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private EvacuationCenter $center;

    private EvacuationEvent $event;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
        $this->barangay = Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $this->event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon', 'event_type' => 'typhoon', 'status' => 'active']);
        $this->center = EvacuationCenter::create(['remote_id' => 7, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'active']);
    }

    private function add(string $name): void
    {
        $this->post(route('ec-board-entries.store', $this->center), [
            'evacuation_event_id' => $this->event->id, 'sex' => 'female', 'age_bracket' => 'adult', 'household_type' => 'new',
            'new_household_barangay_id' => $this->barangay->id, 'new_household_head_name' => $name,
        ])->assertSessionHasNoErrors();
    }

    public function test_step_sends_one_record_at_a_time_oldest_first(): void
    {
        $this->add('Reyes');
        $this->add('Santos');
        Http::fake(['*/evacuees' => Http::response(['data' => ['id' => 50], 'evacuee_id' => 900], 201)]);

        $this->postJson(route('sync.step'))->assertJson(['status' => 'sent', 'remaining' => 1]);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['family_name'] === 'Reyes');
        $this->postJson(route('sync.step'))->assertJson(['status' => 'sent', 'remaining' => 0]);
        $this->postJson(route('sync.step'))->assertJson(['status' => 'empty']);
    }

    public function test_offline_stops_and_keeps_the_record_waiting(): void
    {
        $this->add('Reyes');
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->postJson(route('sync.step'))->assertJson(['status' => 'stopped', 'stop' => 'offline', 'remaining' => 1]);
        $this->assertNull(EcBoardEntry::first()->sync_state);
    }

    public function test_a_refusal_needs_attention_and_try_again_puts_it_back(): void
    {
        $this->add('Reyes');
        Http::fake(['*/evacuees' => Http::response(['message' => 'This belongs to another barangay. You can only work with your own barangay.'], 403)]);

        $this->postJson(route('sync.step'))->assertJson(['status' => 'failed']);
        $this->postJson(route('sync.step'))->assertJson(['status' => 'empty']);
        $this->get(route('sync.index'))->assertOk()->assertSee('This belongs to another barangay.')->assertSee('Try again');

        $this->post(route('sync.retry'), ['key' => 'entry:'.EcBoardEntry::first()->id]);
        $this->assertNull(EcBoardEntry::first()->sync_state);
    }

    public function test_a_401_stops_without_marking_anything(): void
    {
        $this->add('Reyes');
        Http::fake(['*/evacuees' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->postJson(route('sync.step'))->assertJson(['status' => 'stopped', 'stop' => 'auth']);
        $this->assertNull(EcBoardEntry::first()->sync_state);
    }
}
