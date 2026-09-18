<?php

namespace Tests\Feature;

use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers AuthController::login()'s reference-data refresh failure path --
 * previously a bare `catch (\RuntimeException $e) {}` with no visible
 * trace, so a real device could log in "successfully" while its
 * barangay/event/center cache silently stayed stale or partially
 * refreshed (see ReferenceDataPruneTest's own FK-protection test for the
 * exact incident that surfaced this). Login must still succeed either
 * way -- no internet right after login is a normal, expected state this
 * app is built around -- but the failure itself must now be visible.
 */
class LoginReferenceDataWarningTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSuccessfulLogin(): void
    {
        Http::fake([
            '*/auth/login' => Http::response(['data' => [
                'user' => ['id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official', 'barangay' => ['id' => 13, 'name' => 'Binatagan']],
                'token' => 'fresh-token',
            ]]),
        ]);
    }

    public function test_login_succeeds_and_shows_a_visible_warning_when_the_reference_data_refresh_fails(): void
    {
        $this->fakeSuccessfulLogin();
        Http::fake(['*/barangays' => Http::response(['message' => 'Server error'], 500)]);

        $response = $this->post(route('login.submit'), ['email' => 't@example.com', 'password' => 'secret']);

        // Login itself is never blocked by a refresh failure.
        $response->assertRedirect(route('dashboard'));
        $this->assertNotNull(LocalAuth::current());
        $response->assertSessionHas('referenceDataWarning');

        $page = $this->get(route('dashboard'));
        $page->assertSee('refreshing reference data failed', false);
    }

    public function test_login_shows_no_warning_when_the_reference_data_refresh_succeeds(): void
    {
        $this->fakeSuccessfulLogin();
        Http::fake([
            '*/barangays' => Http::response(['data' => []]),
            '*/evacuation-events' => Http::response(['data' => []]),
            '*/evacuation-centers' => Http::response(['data' => []]),
        ]);

        $response = $this->post(route('login.submit'), ['email' => 't@example.com', 'password' => 'secret']);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionMissing('referenceDataWarning');

        $page = $this->get(route('dashboard'));
        $page->assertDontSee('refreshing reference data failed', false);
    }
}
