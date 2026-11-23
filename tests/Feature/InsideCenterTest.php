<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\CenterFamilySnapshot;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 2.1.0 part 4: inside a center, Check out and Transfer per family; Ask another center for space at 90%. */
class InsideCenterTest extends TestCase
{
    use RefreshDatabase;

    private EvacuationCenter $center;

    private EvacuationEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'barangay_name' => 'Bacong', 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $this->event = EvacuationEvent::create(['remote_id' => 79, 'name' => 'Typhoon Drill', 'event_type' => 'typhoon', 'status' => 'active']);
        $this->center = EvacuationCenter::create(['remote_id' => 7, 'barangay_remote_id' => 4, 'name' => 'Bacong Hall', 'status' => 'active',
            'capacity_persons' => 40, 'capacity_source' => 'official', 'current_occupancy' => 20]);
        CenterFamilySnapshot::create(['evacuation_center_id' => $this->center->id, 'evacuation_event_id' => $this->event->id, 'fetched_at' => now(),
            'families' => [['id' => 501, 'name' => 'Jose Reyes', 'here_count' => 4], ['id' => 502, 'name' => 'Rosa Garcia', 'here_count' => 3]]]);
    }

    public function test_each_family_has_check_out_and_transfer(): void
    {
        $this->get(route('evacuation-centers.ec-board', $this->center))
            ->assertSee('data-transfer-family', false)
            ->assertSee('name="families[]" value="remote-501"', false)
            ->assertSee('Check out')
            ->assertDontSee('data-needs-space', false);
    }

    public function test_at_90_percent_the_board_offers_to_ask_another_center_for_space(): void
    {
        $this->center->update(['current_occupancy' => 36]);

        $this->get(route('evacuation-centers.ec-board', $this->center))
            ->assertSee('data-needs-space', false)
            ->assertSee('Bacong Hall is at 90% of its capacity')
            ->assertSee('Ask another center for space');
    }

    public function test_an_unknown_capacity_never_asks_for_space(): void
    {
        $this->center->update(['current_occupancy' => 400, 'capacity_persons' => null, 'capacity_source' => 'unknown']);
        $this->get(route('evacuation-centers.ec-board', $this->center))->assertDontSee('data-needs-space', false);
    }

    public function test_asking_for_space_lets_the_official_choose_the_families_first(): void
    {
        $this->center->update(['current_occupancy' => 38]);

        $this->get(route('transfers.move', [$this->center, 'ask' => 1]))
            ->assertSee('Ask another center for space')
            ->assertSee('data-family-picker', false)
            ->assertSee('Jose Reyes')->assertSee('Rosa Garcia');
        Http::assertNothingSent();

        Http::fake(['*/transfer-requests/suggestions*' => Http::response(['data' => ['centers' => []]])]);
        $this->get(route('transfers.move', [$this->center, 'families' => ['remote-501']]))
            ->assertSee('Move 1 family (4 people)');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'transfer-requests/suggestions') && $r['persons'] === 4);
    }

    public function test_transfer_and_ask_for_space_wait_while_the_event_is_monitored(): void
    {
        $this->event->update(['status' => 'monitoring']);
        $this->center->update(['current_occupancy' => 38]);

        $this->get(route('evacuation-centers.ec-board', $this->center))
            ->assertDontSee('data-transfer-family>', false)
            ->assertDontSee('data-needs-space', false);
    }
}
