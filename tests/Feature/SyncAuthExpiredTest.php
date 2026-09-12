<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EvacuationEvent;
use App\Models\Evacuee;
use App\Models\Family;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncAuthExpiredTest extends TestCase
{
    use RefreshDatabase;

    private function seedPendingFamily(): Family
    {
        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official',
            'api_token' => 'dead-token', 'logged_in_at' => now(),
        ]);
        $barangay = Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $family = Family::create([
            'barangay_id' => $barangay->id, 'evacuation_event_id' => $event->id, 'displacement_type' => 'outside_center',
        ]);
        Evacuee::create([
            'family_id' => $family->id, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
            'sex' => 'male', 'date_of_birth' => '1990-01-01', 'is_head_of_family' => true,
        ]);

        return $family;
    }

    public function test_a_401_during_sync_shows_a_clear_session_expired_message_not_the_raw_backend_error(): void
    {
        $family = $this->seedPendingFamily();

        Http::fake(['*/families/register' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $response = $this->post(route('families.sync'));

        $response->assertRedirect(route('families.index'));
        $response->assertSessionHas('authExpired', 'Your session has expired. Please log in again to continue syncing.');
        $response->assertSessionMissing('status');

        // Not marked as a failed record with the raw backend text -- it's
        // not this record's data that's wrong, the whole session is dead.
        $family->refresh();
        $this->assertNull($family->sync_error);
        $this->assertNull($family->synced_at);
    }

    public function test_a_401_page_shows_the_session_expired_banner_with_a_login_link_not_the_raw_message(): void
    {
        $this->seedPendingFamily();
        Http::fake(['*/families/register' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->post(route('families.sync'));
        $page = $this->get(route('families.index'));

        $page->assertSee('Your session has expired. Please log in again to continue syncing.');
        $page->assertSee(route('logout'), false);
        $page->assertDontSee('Unauthenticated.', false);
    }

    public function test_a_422_validation_failure_still_shows_its_specific_field_level_message_not_the_session_expired_one(): void
    {
        $family = $this->seedPendingFamily();

        Http::fake(['*/families/register' => Http::response([
            'message' => 'The given data was invalid.',
            'errors' => ['evacuation_event_id' => ['The selected evacuation event id is invalid.']],
        ], 422)]);

        $response = $this->post(route('families.sync'));

        $response->assertRedirect(route('families.index'));
        $response->assertSessionMissing('authExpired');
        $response->assertSessionHas('status');

        $family->refresh();
        $this->assertNotNull($family->sync_error);
        $this->assertStringContainsString('evacuation event id is invalid', $family->sync_error);
        $this->assertNull($family->synced_at);

        $page = $this->get(route('families.index'));
        $page->assertSee('evacuation event id is invalid', false);
        $page->assertDontSee('session has expired', false);
    }

    public function test_a_successful_sync_shows_neither_banner(): void
    {
        $this->seedPendingFamily();
        Http::fake(['*/families/register' => Http::response(['data' => ['id' => 42]], 201)]);

        $response = $this->post(route('families.sync'));

        $response->assertSessionMissing('authExpired');
        $response->assertSessionHas('status', '1 record(s) synced successfully.');
    }
}
