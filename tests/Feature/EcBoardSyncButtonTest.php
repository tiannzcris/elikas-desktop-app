<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers the "Sync now" button added directly to the EC Board page, so
 * staff don't have to navigate to Registered Families just to trigger a
 * sync. Same underlying FamilyController::sync() route as before --
 * what's new is that a sync triggered from EC Board redirects back to
 * that SAME center+event afterward (see resolveSyncRedirect()), instead
 * of always landing on Registered Families, so its own pending counts/
 * breakdown reflect the sync immediately. The online/offline button
 * state itself is client-side JS (navigator.onLine) and isn't something
 * PHPUnit's non-JS test client can exercise -- covered instead by
 * confirming the built bundle contains the guard logic and message.
 */
class EcBoardSyncButtonTest extends TestCase
{
    use RefreshDatabase;

    private function login(): void
    {
        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official',
            'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);
    }

    public function test_the_ec_board_page_shows_a_sync_now_button(): void
    {
        $this->login();
        Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Barangay Hall', 'status' => 'active']);

        $page = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));

        $page->assertOk();
        $page->assertSee('Sync now');
        $page->assertSee('Sync requires an internet connection', false);
    }

    public function test_syncing_from_the_ec_board_page_redirects_back_to_that_same_center_and_event(): void
    {
        $this->login();
        Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Barangay Hall', 'status' => 'active']);

        EcBoardEntry::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'sex' => 'male',
            'age_bracket' => 'adult',
            'existing_household_remote_id' => 42,
        ]);

        Http::fake(['*/evacuation-centers/*/evacuees' => Http::response(['data' => ['id' => 100, 'evacuee_id' => 900]], 201)]);

        $response = $this->post(route('families.sync'), [
            'return_to_center_id' => $center->id,
            'return_to_event_id' => $event->id,
        ]);

        $response->assertRedirect(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));
        $response->assertSessionHas('status', '1 record(s) synced successfully.');

        // The redirected-to page itself reflects the just-completed sync.
        $page = $this->get($response->headers->get('Location'));
        $page->assertSee('1 record(s) synced successfully.');
    }

    public function test_syncing_with_no_return_to_center_id_still_redirects_to_registered_families_as_before(): void
    {
        $this->login();
        Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);

        $response = $this->post(route('families.sync'));

        $response->assertRedirect(route('families.index'));
        $response->assertSessionHas('status', 'Nothing to sync -- everything is already up to date.');
    }

    public function test_an_invalid_return_to_center_id_falls_back_to_registered_families_instead_of_erroring(): void
    {
        $this->login();

        $response = $this->post(route('families.sync'), ['return_to_center_id' => 999999]);

        $response->assertRedirect(route('families.index'));
    }

    public function test_an_auth_expired_failure_from_ec_board_also_redirects_back_to_ec_board_not_families_index(): void
    {
        $this->login();
        Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Barangay Hall', 'status' => 'active']);

        EcBoardEntry::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'sex' => 'male',
            'age_bracket' => 'adult',
            'existing_household_remote_id' => 42,
        ]);

        Http::fake(['*/evacuation-centers/*/evacuees' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $response = $this->post(route('families.sync'), [
            'return_to_center_id' => $center->id,
            'return_to_event_id' => $event->id,
        ]);

        $response->assertRedirect(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));
        $response->assertSessionHas('authExpired');
    }

    public function test_the_built_js_bundle_disables_the_sync_button_offline_via_the_existing_connectivity_signal(): void
    {
        $manifestPath = public_path('build/manifest.json');
        if (! file_exists($manifestPath)) {
            $this->markTestSkipped('Frontend assets not built (run `npm run build`) -- nothing to inspect yet.');
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $js = file_get_contents(public_path('build/'.$manifest['resources/js/app.js']['file']));

        // Reuses navigator.onLine (the same signal behind the connection
        // badge above it) -- no separate/new detection logic introduced.
        // The warning message itself is static server-rendered HTML (see
        // partials/_sync_button.blade.php, already confirmed by
        // test_the_ec_board_page_shows_a_sync_now_button above), just
        // toggled visible by this JS -- not part of the bundle itself.
        $this->assertStringContainsString('navigator.onLine', $js);
        $this->assertStringContainsString('data-sync-control', $js);
    }
}
