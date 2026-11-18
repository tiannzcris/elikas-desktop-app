<?php

namespace Tests\Feature;

use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\ServerSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    private function cswd(): void
    {
        LocalAuth::create(['remote_user_id' => 2, 'name' => 'City', 'email' => 'c@x', 'role' => 'cswd_personnel',
            'permissions' => ['operations.access', 'scope.all_barangays', 'page.monitoring', 'count_submissions.review'],
            'api_token' => 't', 'logged_in_at' => now()]);
    }

    private function section(array $data): array
    {
        return ['data' => ['evacuation_event' => ['id' => 9, 'name' => 'DEMO - Typhoon Drill', 'status' => 'active', 'is_demo' => true],
            'scope' => ['barangay' => null], 'generated_at' => '2026-11-16T05:00:00+00:00', 'data' => $data]];
    }

    private function fakeServer(): void
    {
        EvacuationEvent::create(['remote_id' => 9, 'name' => 'DEMO - Typhoon Drill', 'event_type' => 'typhoon', 'status' => 'active', 'is_demo' => true]);
        Http::fake([
            '*/monitoring/overview' => Http::response($this->section(['figures' => ['persons' => 1234, 'families' => 300, 'inside' => 1000,
                'outside' => 234, 'arrived_last_hour' => 12, 'centers_in_use' => 7], 'attention' => [['kind' => 'center', 'level' => 'danger', 'text' => 'Gym is at 95% of capacity.']]])),
            '*/monitoring/centers' => Http::response($this->section(['in_use' => 7, 'total' => 9, 'capacity_not_set' => 1,
                'centers' => [['name' => 'Bacong Gym', 'barangay' => ['name' => 'Bacong'], 'inside' => 95, 'capacity' => 100, 'percent' => 95]]])),
            '*/monitoring/barangays' => Http::response($this->section(['barangays' => [
                ['barangay' => ['id' => 5, 'name' => 'Bonga'], 'status' => 'forced_evacuation', 'label' => 'Forced evacuation', 'rank' => 4,
                    'set_by' => ['id' => 9, 'name' => 'Ana Cruz'], 'set_at' => '2026-11-17T07:05:00+00:00',
                    'order' => ['ordered_by' => 'city_mayor', 'ordered_by_label' => 'the City Mayor', 'ordered_at' => '2026-11-17T07:00:00+00:00',
                        'summary' => 'Forced evacuation ordered by the City Mayor at 3:00 PM, recorded by Ana Cruz']],
                ['barangay' => ['id' => 4, 'name' => 'Bacong'], 'status' => 'watch', 'label' => 'Watch', 'rank' => 1, 'set_by' => ['name' => 'Maria Santos'], 'set_at' => '2026-11-17T06:00:00+00:00', 'order' => null],
                ['barangay' => ['id' => 6, 'name' => 'Busac'], 'status' => 'normal', 'label' => 'Normal', 'rank' => 0, 'order' => null],
            ]])),
            '*/monitoring/vulnerable' => Http::response($this->section(['totals' => ['infants' => 4, 'seniors' => 20, 'pwd' => 3, 'pregnant' => 2, 'lactating' => 1],
                'by_barangay' => [['barangay' => ['name' => 'Bacong'], 'persons' => 50, 'infants' => 4]]])),
            '*/monitoring/requests' => Http::response($this->section(['requests' => [['kind_label' => 'Final count', 'what' => 'Bacong final count',
                'where' => 'Bacong', 'waited_minutes' => 150]]])),
            '*/count-submissions/barangays*' => Http::response(['data' => ['evacuation_event' => ['id' => 9, 'name' => 'DEMO - Typhoon Drill'], 'barangays' => []]]),
            '*/count-submissions?*' => Http::response(['data' => ['data' => [], 'meta' => []]]),
        ]);
    }

    public function test_cswd_reads_monitoring_from_the_copy_on_this_device(): void
    {
        $this->cswd();
        $this->fakeServer();
        $this->post(route('monitoring.refresh'))->assertSessionHasNoErrors();

        // Offline afterwards: the page still shows the copy, read-only.
        Http::fake(fn () => throw new ConnectionException('down'));
        $this->get(route('monitoring.index'))->assertOk()
            ->assertSee('1,234')->assertSee('Gym is at 95% of capacity.')->assertSee('Bacong Gym')
            ->assertSee('Bacong final count')->assertSee('2 h 30 min')
            ->assertSee('Read-only')->assertSee('As of')->assertSee('Validate on the web')
            ->assertSee('data-demo-badge', false)
            // Evacuation orders: who ordered and when (Asia/Manila), read-only.
            ->assertSee('Evacuation orders (1)')
            ->assertSeeInOrder(['Bonga', 'Forced evacuation', 'The City Mayor', 'Nov 17, 3:00 PM', 'Forced evacuation ordered by the City Mayor at 3:00 PM, recorded by Ana Cruz'])
            ->assertSeeInOrder(['Bacong', 'Watch', 'Not an order'])
            ->assertDontSee('Busac');
        $this->assertNotNull(ServerSnapshot::named('monitoring.overview'));
    }

    public function test_officials_do_not_get_monitoring_and_cswd_has_it_in_the_sidebar(): void
    {
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'O', 'email' => 'o@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
        $this->get(route('monitoring.index'))->assertRedirect(route('dashboard'));

        LocalAuth::query()->delete();
        $this->cswd();
        $this->get(route('dashboard'))->assertSee('data-nav-group="city"', false)->assertSee(route('monitoring.index'), false);
    }
}
