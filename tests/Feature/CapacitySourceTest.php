<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\LocalAuth;
use App\Models\ServerSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** capacity_source: official, estimated ("est." with a tooltip) or unknown (no percentage, never full). */
class CapacitySourceTest extends TestCase
{
    use RefreshDatabase;

    private function official(): void
    {
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'barangay_name' => 'Bacong', 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
    }

    public function test_refresh_keeps_the_source_and_basis(): void
    {
        $this->official();
        Http::fake([
            '*/auth/me' => Http::response(['data' => []]),
            '*/barangays' => Http::response(['data' => [['id' => 4, 'name' => 'Bacong']]]),
            '*/evacuation-events' => Http::response(['data' => []]),
            '*/evacuation-centers?detail=1*' => Http::response(['data' => [
                ['id' => 71, 'barangay' => ['id' => 4], 'name' => 'LWCES', 'status' => 'active', 'capacity_persons' => 112, 'current_occupancy' => 48, 'capacity_source' => 'estimated', 'capacity_basis' => 'peak_2025'],
                ['id' => 72, 'barangay' => ['id' => 4], 'name' => 'Chapel', 'status' => 'active', 'capacity_persons' => null, 'current_occupancy' => 9, 'capacity_source' => 'unknown', 'capacity_basis' => null],
            ]]),
            '*/puroks*' => Http::response(['data' => []]),
            '*/private-house-families*' => Http::response(['data' => ['barangays' => []]]),
            '*/transfer-requests*' => Http::response(['data' => ['requests' => []]]),
        ]);

        $this->post(route('reference-data.refresh'))->assertSessionHasNoErrors();

        $this->assertSame(['capacity_source' => 'estimated', 'capacity_basis' => 'peak_2025'], EvacuationCenter::firstWhere('remote_id', 71)->only(['capacity_source', 'capacity_basis']));
        $this->assertTrue(EvacuationCenter::firstWhere('remote_id', 72)->capacityUnknown());
    }

    public function test_cards_say_est_and_unknown_and_an_unknown_capacity_is_never_full(): void
    {
        $this->official();
        EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 4, 'name' => 'Official Hall', 'status' => 'active', 'capacity_persons' => 100, 'capacity_source' => 'official', 'current_occupancy' => 40]);
        EvacuationCenter::create(['remote_id' => 2, 'barangay_remote_id' => 4, 'name' => 'Estimated School', 'status' => 'active', 'capacity_persons' => 112, 'capacity_source' => 'estimated', 'capacity_basis' => 'peak_2025', 'current_occupancy' => 48]);
        $unknown = EvacuationCenter::create(['remote_id' => 3, 'barangay_remote_id' => 4, 'name' => 'Unknown Chapel', 'status' => 'active', 'capacity_persons' => null, 'capacity_source' => 'unknown', 'current_occupancy' => 500]);

        $this->assertSame('active', $unknown->displayStatus(), 'never full without a capacity');
        $this->assertSame('500 / – (capacity unknown)', $unknown->occupancyLabel());

        $page = $this->get(route('ec-board.index'))->assertOk();
        $page->assertSee('40 / 100')->assertSee('people inside · 40%')
            ->assertSee('48 / 112')->assertSee('title="Estimated from the 2025 peak"', false)
            ->assertSee('500 / –')->assertSee('people inside · capacity unknown')
            ->assertSee('1 center with capacity unknown')
            ->assertDontSee('data-center-status="full"', false);
    }

    public function test_the_city_dashboard_shows_transfer_requests_waiting(): void
    {
        LocalAuth::create(['remote_user_id' => 2, 'name' => 'C', 'email' => 'c@x', 'role' => 'cswd_personnel',
            'permissions' => ['operations.access', 'scope.all_barangays', 'page.monitoring', 'page.dashboard'], 'api_token' => 't', 'logged_in_at' => now()]);
        ServerSnapshot::keep('monitoring.overview', ['evacuation_event' => ['id' => 9, 'name' => 'Typhoon'], 'generated_at' => now()->toIso8601String(),
            'data' => ['figures' => ['persons' => 10, 'families' => 3, 'centers_in_use' => 1]]]);
        ServerSnapshot::keep('city_dashboard', ['actions' => ['transfer_requests_pending' => 2], 'capacity' => ['official' => 2, 'estimated' => 149, 'unknown' => 0, 'total' => 151]]);

        $this->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['Transfer requests waiting', '2'])->assertSee('data-transfers-pending', false)
            ->assertSee('Center capacities: 2 official, 149 estimated, 0 unknown.');
    }
}
