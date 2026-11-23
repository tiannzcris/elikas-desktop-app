<?php

namespace Tests\Feature;

use App\Models\AppState;
use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Services\BarangayDashboard;
use App\Services\SelectedEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** docs/backend/apps-api-2026-11-24.md 7: notification type, the barangay dashboard, alerts target_barangay. */
class NotificationsDashboardAlertsTest extends TestCase
{
    use RefreshDatabase;

    private EvacuationEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        LocalAuth::create(['remote_user_id' => 2, 'name' => 'Official One', 'email' => 'brgy.tuburan', 'role' => 'barangay_official',
            'barangay_id' => 1, 'barangay_name' => 'Tuburan', 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 1, 'name' => 'Tuburan']);
        EvacuationEvent::create(['remote_id' => 9, 'name' => 'Typhoon Drill', 'event_type' => 'typhoon', 'status' => 'active']);
        $this->event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon Ofel', 'event_type' => 'typhoon', 'status' => 'monitoring', 'start_date' => '2026-11-01']);
        EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Tuburan Barangay Hall', 'status' => 'on_standby']);
        EvacuationCenter::create(['remote_id' => 2, 'barangay_remote_id' => 1, 'name' => 'Tuburan Gym', 'status' => 'on_standby']);
    }

    private function registrationOpen(): array
    {
        return ['id' => '3f6ea2a1-e4bb-4f1a-9458-54a08673a0d9', 'type' => 'registration_open', 'evacuation_event_id' => 1, 'barangay_id' => 1,
            'title' => 'Forced evacuation ordered for Tuburan: registration is now open',
            'message' => 'CSWD ordered "Forced evacuation" for Tuburan in "Typhoon Ofel". Registration is now open.',
            'href' => '/barangay-dashboard', 'urgent' => false, 'read' => false, 'created_at' => '2026-11-23T16:29:30+00:00'];
    }

    public function test_the_bell_keeps_the_type_and_opens_registration_open_on_the_ec_board_of_its_event(): void
    {
        Http::fake(['*/notifications' => Http::response(['data' => ['unread' => 1, 'notifications' => [$this->registrationOpen(),
            ['id' => 'x', 'type' => 'correction_requested', 'title' => 'Correction requested: Santos', 'href' => '/families/5', 'read' => true]]]])]);

        $feed = $this->getJson(route('notifications.feed'))->json('notifications');
        $this->assertSame(['registration_open', true], [$feed[0]['type'], $feed[0]['in_app']]);
        $this->assertStringContainsString('type=registration_open', $feed[0]['open']);
        $this->assertStringContainsString('event=1', $feed[0]['open']);
        $this->assertFalse($feed[1]['in_app'], 'Unknown to the desktop: opens on the web.');

        // Opening it: the server says the event is active now; work on it, on the EC Board.
        Http::fake([
            '*/auth/me' => Http::response(['data' => []]),
            '*/barangays' => Http::response(['data' => [['id' => 1, 'name' => 'Tuburan']]]),
            '*/evacuation-events' => Http::response(['data' => [['id' => 1, 'name' => 'Typhoon Ofel', 'event_type' => 'typhoon', 'status' => 'active'],
                ['id' => 9, 'name' => 'Typhoon Drill', 'event_type' => 'typhoon', 'status' => 'active']]]),
            '*/selected-event' => Http::response(['data' => ['selected' => ['id' => 1], 'chosen' => true]]),
            '*' => Http::response(['data' => []]),
        ]);
        $this->get($feed[0]['open'])->assertRedirect(route('ec-board.index'));
        $this->assertSame(1, app(SelectedEvent::class)->get()->remote_id);
        $this->assertSame('active', $this->event->fresh()->status);
    }

    public function test_a_count_submitted_on_behalf_opens_the_final_count(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);
        $this->get(route('notifications.open', ['type' => 'count_submitted_on_behalf', 'event' => 1, 'href' => '/count-submissions']))
            ->assertRedirect(route('final-count.index'));
    }

    public function test_the_dashboard_shows_todo_readiness_and_the_alerts_target(): void
    {
        app(SelectedEvent::class)->choose($this->event);
        Http::fake([
            '*/dashboard/barangay*' => Http::response(['data' => [
                'centers' => [['id' => 1, 'name' => 'Tuburan Barangay Hall', 'readiness' => 'no_checklist'], ['id' => 2, 'name' => 'Tuburan Gym', 'readiness' => 'below']],
                'alerts' => [['id' => 1, 'title' => 'Typhoon signal no. 2', 'severity' => 'advisory', 'date_sent' => '2026-11-23T16:30:22+00:00']],
                'todo' => [
                    ['kind' => 'center_location', 'text' => '1 center has no map location', 'detail' => 'Tuburan Barangay Hall. Families and CSWD cannot find them on the map.', 'href' => '/gis-map?locate=1', 'action' => 'Set location'],
                    ['kind' => 'supplies_short', 'center_id' => 2, 'text' => 'Tuburan Gym: short of the planning standard', 'detail' => '2 toilets, 2 bathing cubicles for a full center (60 persons).', 'href' => '/facility-requests?request=2', 'action' => 'Request supplies'],
                    ['kind' => 'something_new', 'text' => 'Ignored'],
                ],
            ]]),
            '*/alerts*' => Http::response(['data' => ['data' => [
                ['id' => 1, 'title' => 'Typhoon signal no. 2', 'severity' => 'advisory', 'date_sent' => '2026-11-23T16:30:22.000000Z', 'target_barangay' => ['id' => 1, 'name' => 'Tuburan']],
                ['id' => 2, 'title' => 'City advisory', 'severity' => 'info', 'date_sent' => '2026-11-23T15:30:22.000000Z', 'target_barangay' => null],
            ]]]),
        ]);
        app(BarangayDashboard::class)->refresh(LocalAuth::current(), $this->event);

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('data-todo-kind="center_location"', false)->assertSee('1 center has no map location')->assertSee('Set location')
            ->assertSee('Request supplies')->assertDontSee('Ignored')
            ->assertSee('data-readiness="below"', false)->assertSee('Below standard')->assertSee('No facilities checklist')
            ->assertSeeInOrder(['Typhoon signal no. 2', 'Barangay Tuburan', 'Advisory', 'City advisory', 'Whole city', 'Info']);
    }

    public function test_without_the_staff_list_the_dashboards_alerts_show_without_a_target(): void
    {
        $this->assertNull(BarangayDashboard::target(['title' => 'x']));
        $this->assertSame('Whole city', BarangayDashboard::target(['target_barangay' => null]));
        $this->assertSame('Barangay Tuburan', BarangayDashboard::target(['target_barangay' => ['id' => 1, 'name' => 'Tuburan']]));
    }
}
