<?php

namespace Tests\Feature;

use App\Models\AppState;
use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\PendingPrivateHouseFamily;
use App\Services\SelectedEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 2.1.0 part 2: while CSWD monitors an event, registering, transfers and
 * final counts wait for "Start evacuation" (one lock design); an official
 * can say families are arriving; an active event says registration is open.
 */
class EventLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const LOCK = '"Incoming Typhoon" is being monitored. Registering evacuees opens when CSWD starts the evacuation.';

    private EvacuationCenter $center;

    private EvacuationEvent $watch;

    protected function setUp(): void
    {
        parent::setUp();
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'barangay_name' => 'Bacong', 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $this->center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'active']);
        $this->watch = EvacuationEvent::create(['remote_id' => 80, 'name' => 'Incoming Typhoon', 'event_type' => 'typhoon', 'status' => 'monitoring']);
    }

    public function test_while_monitored_registering_waits_with_the_lock_design(): void
    {
        $this->get(route('evacuation-centers.ec-board', $this->center))
            ->assertSee('data-lock-notice="monitoring"', false)
            ->assertSee(e(self::LOCK), false)
            ->assertDontSee('class="btn btn-primary" data-open-board-modal', false);

        $this->post(route('ec-board-entries.store', $this->center), [
            'evacuation_event_id' => $this->watch->id, 'sex' => 'female', 'age_bracket' => 'adult', 'household_type' => 'new',
            'new_household_barangay_id' => Barangay::first()->id, 'new_household_head_name' => 'Reyes',
        ])->assertSessionHasErrors(['evacuation_event_id' => self::LOCK]);
        $this->assertSame(0, EcBoardEntry::count());
    }

    public function test_private_houses_returning_families_and_transfers_wait_too(): void
    {
        $this->post(route('private-houses.store'), [
            'head' => ['first_name' => 'Rosa', 'last_name' => 'Santos', 'sex' => 'female', 'age' => 40], 'host_name' => 'Carmen', 'host_address' => 'Zone 3',
        ])->assertSessionHasErrors(['evacuation_event_id' => '"Incoming Typhoon" is being monitored. Registering families in private houses opens when CSWD starts the evacuation.']);
        $this->assertSame(0, PendingPrivateHouseFamily::count());
        $this->get(route('private-houses.index'))->assertSee('data-lock-notice="monitoring"', false)->assertDontSee(route('private-houses.create'), false);

        $this->post(route('transfers.move-direct', $this->center), ['families' => ['remote-1'], 'to_center_id' => $this->center->id])
            ->assertSessionHasErrors(['evacuation_event_id' => '"Incoming Typhoon" is being monitored. Transfers opens when CSWD starts the evacuation.']);
        $this->get(route('transfers.move', $this->center))->assertRedirect(route('evacuation-centers.ec-board', $this->center));
        $this->post(route('transfers.decide', 5), ['decision' => 'accept'])->assertSessionHasErrors('decide');
        Http::assertNothingSent();
    }

    public function test_an_official_says_families_are_arriving(): void
    {
        Http::fake(['*/evacuation-events/80/families-arriving' => Http::response(['message' => 'CSWD was told.', 'data' => null])]);

        $this->get(route('dashboard'))->assertSee('data-families-arriving', false)->assertSee('Families are arriving');
        $this->post(route('events.families-arriving'), ['note' => 'About 10 families at the gym'])
            ->assertSessionHas('status', 'CSWD was told.');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/evacuation-events/80/families-arriving')
            && $r['note'] === 'About 10 families at the gym');
        $this->assertSame('monitoring', $this->watch->fresh()->status);
    }

    public function test_families_arriving_offline_says_so(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));
        $this->post(route('events.families-arriving'))->assertSessionHasErrors(['arriving' => 'Needs a connection. CSWD was not told yet; try again when online.']);
    }

    public function test_an_active_event_says_registration_is_open(): void
    {
        $this->watch->update(['status' => 'active']);

        $this->get(route('dashboard'))->assertSee('data-registration-open', false)->assertSee('Registration is open')
            ->assertDontSee('data-families-arriving', false);
        $this->get(route('evacuation-centers.ec-board', $this->center))->assertSee('class="btn btn-primary" data-open-board-modal', false);
    }

    public function test_the_default_event_is_active_first_then_monitoring_then_newest(): void
    {
        EvacuationEvent::create(['remote_id' => 79, 'name' => 'Typhoon Drill', 'event_type' => 'typhoon', 'status' => 'active', 'is_demo' => true, 'start_date' => '2026-11-01']);
        EvacuationEvent::create(['remote_id' => 81, 'name' => 'Real but monitored', 'event_type' => 'typhoon', 'status' => 'monitoring', 'start_date' => '2026-11-20']);

        // A practice event is not set apart: active wins.
        $this->assertSame(79, app(SelectedEvent::class)->pick()->remote_id);

        EvacuationEvent::where('remote_id', 79)->update(['status' => 'closed']);
        $this->assertSame(81, app(SelectedEvent::class)->pick()->remote_id);
    }

    public function test_login_resets_the_selected_event_to_the_default(): void
    {
        LocalAuth::query()->delete();
        AppState::put('selected_event', ['remote_id' => 80, 'unsent' => true]);
        Http::fake([
            '*/auth/login' => Http::response(['data' => ['token' => 'n', 'user' => ['id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
                'permissions' => ['operations.access'], 'barangay' => ['id' => 4, 'name' => 'Bacong']]]]),
            '*/barangays' => Http::response(['data' => [['id' => 4, 'name' => 'Bacong']]]),
            '*/evacuation-events' => Http::response(['data' => [['id' => 80, 'name' => 'Incoming Typhoon', 'event_type' => 'typhoon', 'status' => 'monitoring']]]),
            '*/selected-event' => Http::response(['data' => ['selected' => ['id' => 80], 'chosen' => false]]),
            '*/evacuation-centers*' => Http::response(['data' => []]),
            '*' => Http::response(['data' => []]),
        ]);

        $this->post(route('login.submit'), ['email' => 't@x', 'password' => 'secret'])->assertRedirect(route('dashboard'));

        // The old choice was never sent (no PUT), and nothing is chosen now.
        Http::assertNotSent(fn ($r) => $r->method() === 'PUT');
        $this->assertFalse(app(SelectedEvent::class)->chosen());
    }
}
