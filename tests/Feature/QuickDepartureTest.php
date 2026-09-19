<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers "Quick Departure" -- the EC Board's reverse counterpart to "Add
 * Evacuee", mirroring the web dashboard's own proven design (elikas-backend
 * commit 5cc8830). Deliberately has NO offline/local path at all, unlike
 * every other write on this page: it needs the central server's own true
 * current "who's here" set to correctly select who departs, which this
 * device's local cache can never guarantee reflects (see
 * EvacuationCenterController::quickDeparture()'s own docblock). The
 * online-only gating itself is client-side JS (the shared data-sync-button
 * wiring in app.js) and isn't something PHPUnit's non-JS test client can
 * exercise directly -- covered instead by confirming the view reuses that
 * exact existing markup pattern, same approach EcBoardSyncButtonTest takes
 * for Sync Now's own offline guard.
 */
class QuickDepartureTest extends TestCase
{
    use RefreshDatabase;

    private function login(): void
    {
        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official',
            'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);
    }

    private function seedCenterAndEvent(): array
    {
        Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Center One', 'status' => 'active']);

        return [$center, $event];
    }

    public function test_the_ec_board_page_shows_a_quick_departure_card_reusing_the_sync_button_offline_guard(): void
    {
        $this->login();
        [$center] = $this->seedCenterAndEvent();

        $page = $this->get(route('evacuation-centers.ec-board', $center));

        $page->assertOk();
        $page->assertSee('Quick departure');
        $page->assertSee('Quick departure requires an internet connection', false);
        // Reuses the exact same data-sync-button/data-sync-control wiring
        // already established for Sync Now -- no new connectivity-
        // detection markup introduced for this button.
        $page->assertSee('data-sync-control', false);
        $page->assertSee('data-quick-departure-submit', false);
    }

    public function test_quick_departure_calls_the_real_backend_endpoint_and_returns_success(): void
    {
        $this->login();
        [$center, $event] = $this->seedCenterAndEvent();

        Http::fake([
            '*/evacuation-centers/1/quick-departure' => Http::response([
                'success' => true,
                'message' => '2 evacuee(s) marked as departed.',
            ], 200),
        ]);

        $response = $this->postJson(route('evacuation-centers.quick-departure', $center), [
            'evacuation_event_id' => $event->id,
            'age_bracket' => 'adult',
            'sex' => 'male',
            'quantity' => 2,
            'status' => 'returned_home',
        ]);

        $response->assertOk();
        $response->assertJson(['message' => '2 evacuee(s) marked as departed.']);

        Http::assertSent(function ($request) use ($event) {
            return str_contains($request->url(), '/evacuation-centers/1/quick-departure')
                && $request['evacuation_event_id'] === $event->remote_id
                && $request['age_bracket'] === 'adult'
                && $request['sex'] === 'male'
                && $request['quantity'] === 2
                && $request['status'] === 'returned_home';
        });
    }

    /**
     * The exact real-world case this whole feature has to get right: the
     * backend's own "only N available" 422 message, passed through
     * verbatim -- not a generic fallback -- so staff see exactly why the
     * request was refused.
     */
    public function test_insufficient_quantity_shows_the_backends_exact_message(): void
    {
        $this->login();
        [$center, $event] = $this->seedCenterAndEvent();

        Http::fake([
            '*/evacuation-centers/1/quick-departure' => Http::response([
                'success' => false,
                'message' => 'Only 3 matching evacuee(s) are currently here, cannot mark 5 as departed.',
            ], 422),
        ]);

        $response = $this->postJson(route('evacuation-centers.quick-departure', $center), [
            'evacuation_event_id' => $event->id,
            'age_bracket' => 'adult',
            'sex' => 'male',
            'quantity' => 5,
            'status' => 'returned_home',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Only 3 matching evacuee(s) are currently here, cannot mark 5 as departed.']);
    }

    public function test_a_401_from_the_central_server_is_surfaced_as_a_session_expired_message(): void
    {
        $this->login();
        [$center, $event] = $this->seedCenterAndEvent();

        Http::fake([
            '*/evacuation-centers/1/quick-departure' => Http::response(['message' => 'Unauthenticated.'], 401),
        ]);

        $response = $this->postJson(route('evacuation-centers.quick-departure', $center), [
            'evacuation_event_id' => $event->id,
            'age_bracket' => 'adult',
            'sex' => 'male',
            'quantity' => 1,
            'status' => 'returned_home',
        ]);

        $response->assertStatus(401);
    }

    public function test_a_connection_failure_returns_a_clear_offline_message_without_ever_hitting_the_network_twice(): void
    {
        $this->login();
        [$center, $event] = $this->seedCenterAndEvent();

        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Could not connect');
        });

        $response = $this->postJson(route('evacuation-centers.quick-departure', $center), [
            'evacuation_event_id' => $event->id,
            'age_bracket' => 'adult',
            'sex' => 'male',
            'quantity' => 1,
            'status' => 'returned_home',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Could not reach the central server.']);
    }

    public function test_validation_rejects_a_bad_age_bracket_status_or_zero_quantity(): void
    {
        $this->login();
        [$center, $event] = $this->seedCenterAndEvent();

        $response = $this->postJson(route('evacuation-centers.quick-departure', $center), [
            'evacuation_event_id' => $event->id,
            'age_bracket' => 'not-a-real-bracket',
            'sex' => 'male',
            'quantity' => 0,
            'status' => 'not-a-real-status',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['age_bracket', 'quantity', 'status']);
    }

    public function test_logged_out_requests_are_refused_with_a_401(): void
    {
        [$center, $event] = $this->seedCenterAndEvent();

        $response = $this->postJson(route('evacuation-centers.quick-departure', $center), [
            'evacuation_event_id' => $event->id,
            'age_bracket' => 'adult',
            'sex' => 'male',
            'quantity' => 1,
            'status' => 'returned_home',
        ]);

        $response->assertStatus(401);
    }
}
