<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\EvacuationCenterSectoralSnapshot;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The EC Information Board header, as on the official template: No. of
 * Families (Cum/Now), No. of Persons (Cum/Now) and "As of" -- when the
 * board's figures last arrived from the central server, which is what an
 * offline board keeps showing.
 */
class EcBoardHeaderTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: EvacuationEvent, 1: EvacuationCenter} */
    private function seedBase(): array
    {
        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official',
            'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);
        Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Barangay Hall', 'status' => 'active']);

        return [$event, $center];
    }

    private function fakeServer(): void
    {
        Http::fake(['*/evacuation-centers/1/quick-count*' => Http::response(['data' => [
            'families_cumulative' => 12,
            'families_now' => 9,
            'persons_cumulative' => 40,
            'persons_now' => 31,
            'beneficiaries_4ps' => 4,
            'age_groups' => [['age_bracket' => 'adult', 'male_count' => 16, 'female_count' => 15]],
            'sectoral_groups' => [],
        ]])]);
    }

    public function test_a_refresh_caches_the_header_figures_and_shows_them_in_the_templates_wording(): void
    {
        [$event, $center] = $this->seedBase();
        $this->fakeServer();
        $this->travelTo(Carbon::parse('2026-09-27 01:30:00', 'UTC'));

        $response = $this->get(route('evacuation-centers.board-refresh', $center).'?event='.$event->id);

        $response->assertOk();
        $response->assertSeeInOrder(['As of:', 'No. of Families (Cum/Now)', '12/9', 'No. of Persons (Cum/Now)', '40/31', '4Ps beneficiary families', '4']);
        $response->assertSee('datetime="2026-09-27T01:30:00+00:00"', false);

        $snapshot = EvacuationCenterSectoralSnapshot::sole();
        $this->assertSame([12, 9, 40, 31], [$snapshot->families_cumulative, $snapshot->families_now, $snapshot->persons_cumulative, $snapshot->persons_now]);
        $this->assertTrue($snapshot->fetched_at->equalTo(Carbon::parse('2026-09-27 01:30:00', 'UTC')));
    }

    public function test_offline_the_board_keeps_the_last_fetched_figures_and_their_as_of_not_the_current_time(): void
    {
        [$event, $center] = $this->seedBase();
        $this->fakeServer();
        $this->travelTo(Carbon::parse('2026-09-27 01:30:00', 'UTC'));
        $this->get(route('evacuation-centers.board-refresh', $center).'?event='.$event->id)->assertOk();

        // Three hours later the device is offline: the refresh fails and
        // changes nothing.
        $this->travelTo(Carbon::parse('2026-09-27 04:30:00', 'UTC'));
        Http::fake(['*' => fn () => throw new ConnectionException('offline')]);
        $this->get(route('evacuation-centers.board-refresh', $center).'?event='.$event->id)->assertStatus(503);

        $page = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));

        $page->assertOk();
        $page->assertSee('datetime="2026-09-27T01:30:00+00:00"', false);
        $page->assertDontSee('2026-09-27T04:30', false);
        $page->assertSeeInOrder(['No. of Families (Cum/Now)', '12/9', 'No. of Persons (Cum/Now)', '40/31']);
    }

    public function test_a_board_never_fetched_says_so_instead_of_showing_a_time_or_zeros(): void
    {
        [$event, $center] = $this->seedBase();

        $page = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));

        $page->assertOk();
        $page->assertSee('As of:');
        $page->assertSee('not yet fetched from the central server');
        $page->assertDontSee('data-local-time', false);
        $page->assertSeeInOrder(['No. of Families (Cum/Now)', '—/—', 'No. of Persons (Cum/Now)', '—/—']);
    }
}
