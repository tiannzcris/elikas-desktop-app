<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationCenterSectoralSnapshot;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers the sectoral display's two halves: "Last known" (the central
 * server's own live board, cached in EvacuationCenterSectoralSnapshot) and
 * "Added on this device" (this device's not-yet-synced Add Evacuee
 * entries, counted by the server's own rule). Nothing here is typed in.
 */
class SectoralDualViewTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Barangay, 1: EvacuationEvent, 2: EvacuationCenter} */
    private function seedBase(): array
    {
        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official',
            'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);
        $barangay = Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Barangay Hall', 'status' => 'active']);

        return [$barangay, $event, $center];
    }

    public function test_the_sectoral_refresh_endpoint_fetches_and_caches_the_last_known_snapshot(): void
    {
        [, $event, $center] = $this->seedBase();

        Http::fake([
            '*/evacuation-centers/1/quick-count*' => Http::response(['data' => [
                'beneficiaries_4ps' => 7,
                'sectoral_groups' => [
                    ['sectoral_group' => 'pwd', 'male_count' => 2, 'female_count' => 1],
                ],
                'updated_by_name' => 'Central Staff',
                'updated_at' => '2026-09-01T10:00:00Z',
            ]]),
        ]);

        $response = $this->get(route('evacuation-centers.sectoral-refresh', $center).'?event='.$event->id);

        $response->assertOk();
        $response->assertSee('7');
        $response->assertSee('Central Staff');

        $this->assertDatabaseHas('evacuation_center_sectoral_snapshots', [
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'beneficiaries_4ps' => 7,
            'updated_by_name' => 'Central Staff',
        ]);
    }

    /**
     * Seeds genuinely DIFFERENT values on each side to prove neither card
     * is silently reading the other's data.
     */
    public function test_the_last_known_and_this_device_views_stay_separate_not_merged(): void
    {
        [, $event, $center] = $this->seedBase();

        EvacuationCenterSectoralSnapshot::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'beneficiaries_4ps' => 3,
            'sectoral_groups' => [['sectoral_group' => 'pwd', 'male_count' => 31, 'female_count' => 0]],
        ]);
        EcBoardEntry::create([
            'evacuation_center_id' => $center->id, 'evacuation_event_id' => $event->id,
            'sex' => 'female', 'age_bracket' => 'adult', 'existing_household_remote_id' => 9, 'is_pwd' => true,
        ]);

        $response = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));

        $response->assertOk();
        $response->assertSee('31'); // last known
        $rows = collect($response->viewData('pendingSectoral'))->keyBy('label');
        $this->assertSame(['label' => 'Persons with disability (PWD)', 'male' => 0, 'female' => 1], $rows['Persons with disability (PWD)']);
    }

    /**
     * 4Ps families is live on the central server, so the header only ever
     * shows the last-known server figure.
     */
    public function test_the_header_4ps_figure_is_the_servers(): void
    {
        [, $event, $center] = $this->seedBase();

        EvacuationCenterSectoralSnapshot::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'beneficiaries_4ps' => 23,
            'sectoral_groups' => [],
        ]);

        $page = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));

        $page->assertOk();
        $page->assertSee('23');
    }
}
