<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Services\SelectedEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Two open events sharing a center
 * (docs/backend/apps-api-2026-11-center-figures-per-event.md 3): Typhoon
 * Drill (#48, active) has 87 people in LWCES (B), capacity 1,088; Incoming
 * Typhoon (#49, monitoring) has nobody there.
 */
class CenterFiguresPerEventTest extends TestCase
{
    use RefreshDatabase;

    private EvacuationEvent $drill;

    private EvacuationEvent $incoming;

    private EvacuationCenter $center;

    protected function setUp(): void
    {
        parent::setUp();
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $this->drill = EvacuationEvent::create(['remote_id' => 48, 'name' => 'Typhoon Drill', 'event_type' => 'typhoon', 'status' => 'active', 'start_date' => '2026-11-20']);
        $this->incoming = EvacuationEvent::create(['remote_id' => 49, 'name' => 'Incoming Typhoon', 'event_type' => 'typhoon', 'status' => 'monitoring', 'start_date' => '2026-11-24']);
        // As cached at the last refresh, with the drill selected.
        $this->center = EvacuationCenter::create(['remote_id' => 7, 'barangay_remote_id' => 4, 'name' => 'LWCES (B)', 'status' => 'active',
            'capacity_persons' => 1088, 'current_occupancy' => 87, 'total_occupancy' => 87, 'effective_status' => 'active']);
        app(SelectedEvent::class)->choose($this->drill);
    }

    /** The server's rows for one event, as in the backend doc's example. */
    private function serverRow(?int $eventId): array
    {
        $forDrill = $eventId !== 49;

        return [
            'id' => 7, 'barangay_id' => 4, 'name' => 'LWCES (B)', 'status' => 'active', 'approval_status' => 'approved',
            'capacity_persons' => 1088, 'current_occupancy' => $forDrill ? 87 : 0, 'occupancy_percent' => $forDrill ? 8.0 : 0.0,
            'effective_status' => $forDrill ? 'active' : 'on_standby', 'total_occupancy' => 87,
            'other_events' => $forDrill ? [] : [['event_id' => 48, 'name' => 'Typhoon Drill', 'status' => 'active', 'persons' => 87, 'families' => 21]],
            'occupancy_note' => $forDrill ? null : '87 people here for Typhoon Drill',
        ];
    }

    private function fakeServer(): void
    {
        $selected = null;
        Http::fake(function (Request $request) use (&$selected) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/selected-event')) {
                // The server remembers what was PUT.
                if ($request->method() === 'PUT') {
                    $selected = $request['evacuation_event_id'];
                }

                return Http::response(['data' => ['selected' => ['id' => $selected], 'chosen' => $selected !== null]]);
            }
            if (str_ends_with($path, '/evacuation-events')) {
                return Http::response(['data' => [
                    ['id' => 48, 'name' => 'Typhoon Drill', 'event_type' => 'typhoon', 'status' => 'active'],
                    ['id' => 49, 'name' => 'Incoming Typhoon', 'event_type' => 'typhoon', 'status' => 'monitoring'],
                ]]);
            }
            if (str_ends_with($path, '/barangays')) {
                return Http::response(['data' => [['id' => 4, 'name' => 'Bacong']]]);
            }
            if (str_contains($request->url(), '/evacuation-centers?')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return Http::response(['data' => [$this->serverRow(isset($query['evacuation_event_id']) ? (int) $query['evacuation_event_id'] : null)]]);
            }

            return Http::response(['data' => []]);
        });
    }

    public function test_switching_events_reloads_the_centers_and_every_figure_follows(): void
    {
        $this->fakeServer();

        $this->from(route('ec-board.index'))->post(route('selected-event.update'), ['event' => $this->incoming->id])->assertRedirect();

        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), 'evacuation-centers?detail=1&evacuation_event_id=49'));
        $center = $this->center->fresh();
        $this->assertSame([0, 87, 'on_standby', '87 people here for Typhoon Drill'],
            [$center->occupancyHere(), $center->occupancyTotal(), $center->displayStatus(), $center->occupancy_note]);
        $this->get(route('ec-board.index'))->assertOk()
            ->assertSee('0 / 1,088')->assertSee('data-center-status="on_standby"', false)->assertSee('87 people here for Typhoon Drill');
        $this->get(route('dashboard'))->assertOk()->assertSee('87 people here for Typhoon Drill');
        $this->get(route('evacuation-centers.ec-board', $this->center))->assertOk()->assertSee('data-occupancy-note', false);

        // Back to the drill: its own 87, active, no note.
        $this->from(route('ec-board.index'))->post(route('selected-event.update'), ['event' => $this->drill->id])->assertRedirect();
        $center = $this->center->fresh();
        $this->assertSame([87, 'active', null], [$center->occupancyHere(), $center->displayStatus(), $center->occupancy_note]);
        $this->get(route('ec-board.index'))->assertSee('87 / 1,088')->assertSee('data-center-status="active"', false)
            ->assertDontSee('data-occupancy-note', false);
    }

    public function test_a_switch_made_offline_keeps_the_old_figures_without_waiting_on_the_centers(): void
    {
        $tried = [];
        Http::fake(function (Request $request) use (&$tried) {
            $tried[] = $request->method().' '.parse_url($request->url(), PHP_URL_PATH);
            throw new \Illuminate\Http\Client\ConnectionException('down');
        });

        $this->from(route('ec-board.index'))->post(route('selected-event.update'), ['event' => $this->incoming->id])->assertRedirect();

        // Only the PUT: no second wait for the centers.
        $this->assertCount(1, $tried);
        $this->assertStringStartsWith('PUT', $tried[0]);
        $this->assertSame(87, $this->center->fresh()->current_occupancy);
    }

    public function test_refresh_asks_for_the_selected_events_figures_and_keeps_the_note(): void
    {
        // Chosen offline: sent first, then the centers are asked for it.
        app(SelectedEvent::class)->choose($this->incoming);
        $this->fakeServer();

        $this->post(route('reference-data.refresh'))->assertSessionHasNoErrors();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'evacuation-centers?detail=1&evacuation_event_id=49'));
        $center = $this->center->fresh();
        $this->assertSame([0, 87, 'on_standby', '87 people here for Typhoon Drill'],
            [(int) $center->current_occupancy, (int) $center->total_occupancy, $center->effective_status, $center->occupancy_note]);
    }

    public function test_the_stored_status_is_not_shown_and_only_the_selected_events_arrivals_count(): void
    {
        // Set "active" by officials, nobody inside for this event: on standby, as the server says.
        $this->center->update(['current_occupancy' => 0, 'effective_status' => 'on_standby', 'total_occupancy' => 87]);
        app(SelectedEvent::class)->choose($this->incoming);
        $this->assertSame('on_standby', $this->center->fresh()->displayStatus());

        // An arrival for the drill waiting on this device: not this event's.
        EcBoardEntry::create(['evacuation_center_id' => $this->center->id, 'evacuation_event_id' => $this->drill->id, 'sex' => 'male', 'age_bracket' => 'adult']);
        $center = $this->center->fresh();
        $this->assertSame([0, 88, 'on_standby'], [$center->occupancyHere(), $center->occupancyTotal(), $center->displayStatus()]);

        // One for the selected event makes it active here.
        EcBoardEntry::create(['evacuation_center_id' => $this->center->id, 'evacuation_event_id' => $this->incoming->id, 'sex' => 'female', 'age_bracket' => 'adult']);
        $center = $this->center->fresh();
        $this->assertSame([1, 89, 'active'], [$center->occupancyHere(), $center->occupancyTotal(), $center->displayStatus()]);
    }

    public function test_full_and_asking_for_space_count_everyone_inside(): void
    {
        // 10 here for the drill, 79 more for the other event: 89 of 100 inside.
        $this->center->update(['capacity_persons' => 100, 'current_occupancy' => 10, 'total_occupancy' => 89, 'effective_status' => 'nearly_full']);
        $center = $this->center->fresh();
        $this->assertSame(['nearly_full', false], [$center->displayStatus(), $center->needsSpace()]);

        // One more arrival for the OTHER open event, still on this device: 90%.
        EcBoardEntry::create(['evacuation_center_id' => $this->center->id, 'evacuation_event_id' => $this->incoming->id, 'sex' => 'male', 'age_bracket' => 'adult']);
        $center = $this->center->fresh();
        $this->assertSame([10, 90, 'full', true], [$center->occupancyHere(), $center->occupancyTotal(), $center->displayStatus(), $center->needsSpace()]);

        $this->get(route('evacuation-centers.ec-board', $this->center))->assertOk()
            ->assertSee('LWCES (B) is at 90% of its capacity')->assertSee('90 / 100, everyone inside');
    }

    public function test_a_practice_event_does_not_count_a_real_events_arrivals(): void
    {
        $this->drill->update(['is_demo' => true]);
        $real = EvacuationEvent::create(['remote_id' => 50, 'name' => 'Real Typhoon', 'event_type' => 'typhoon', 'status' => 'active']);
        $this->center->update(['capacity_persons' => 100, 'current_occupancy' => 89, 'total_occupancy' => 89]);
        EcBoardEntry::create(['evacuation_center_id' => $this->center->id, 'evacuation_event_id' => $real->id, 'sex' => 'male', 'age_bracket' => 'adult']);

        $this->assertSame([89, false], [$this->center->fresh()->occupancyTotal(), $this->center->fresh()->needsSpace()]);
    }
}
