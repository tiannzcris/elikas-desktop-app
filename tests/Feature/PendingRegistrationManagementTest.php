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

class PendingRegistrationManagementTest extends TestCase
{
    use RefreshDatabase;

    private function login(): LocalAuth
    {
        return LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official',
            'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);
    }

    private function baseFamily(Barangay $barangay, EvacuationEvent $event, ?string $syncError = null): Family
    {
        $family = Family::create([
            'barangay_id' => $barangay->id,
            'evacuation_event_id' => $event->id,
            'displacement_type' => 'outside_center',
            'sync_error' => $syncError,
        ]);

        Evacuee::create([
            'family_id' => $family->id,
            'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
            'sex' => 'male', 'date_of_birth' => '1990-01-01',
            'contact_number' => '09171234567', 'is_head_of_family' => true,
        ]);

        return $family;
    }

    // -----------------------------------------------------------------
    // Confirms the pre-fix gap: before these routes existed, there was no
    // way to touch a specific pending registration at all.
    // -----------------------------------------------------------------
    public function test_pending_queue_has_no_way_to_act_on_a_specific_item_without_the_new_routes(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('families.edit'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('families.update'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('families.destroy'));
    }

    public function test_delete_removes_a_pending_registration_and_its_members(): void
    {
        $this->login();
        $barangay = Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $family = $this->baseFamily($barangay, $event, 'The selected evacuation event id is invalid.');

        $response = $this->delete(route('families.destroy', $family));

        $response->assertRedirect(route('families.index'));
        $this->assertDatabaseMissing('families', ['id' => $family->id]);
        $this->assertDatabaseMissing('evacuees', ['family_id' => $family->id]);
    }

    public function test_delete_is_refused_for_an_already_synced_family(): void
    {
        $this->login();
        $barangay = Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $family = $this->baseFamily($barangay, $event);
        $family->update(['synced_at' => now(), 'remote_id' => 999]);

        $this->delete(route('families.destroy', $family));

        $this->assertDatabaseHas('families', ['id' => $family->id]);
    }

    /**
     * Scenario 1 from the bug report: a member's contact_number is stored
     * locally in a format the CENTRAL server's stricter regex rejects (this
     * app's own local validation has no such regex -- see
     * RegisterFamilyRequest -- so a bad number sails through store() and
     * only ever surfaces as a sync failure). Confirms the edit screen lets
     * it actually be corrected, and that the correction sticks through a
     * subsequent successful sync.
     */
    public function test_editing_corrects_a_permanently_failing_bad_contact_number(): void
    {
        $this->login();
        $barangay = Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $family = $this->baseFamily($barangay, $event);
        $family->evacuees()->first()->update(['contact_number' => 'not-a-real-number']);
        $family->update(['sync_error' => 'The members.0.contact_number format is invalid.']);

        // Edit form loads and reflects the current (bad) data.
        $editResponse = $this->get(route('families.edit', $family));
        $editResponse->assertOk();
        $editResponse->assertSee('not-a-real-number');

        $update = $this->put(route('families.update', $family), [
            'barangay_id' => $barangay->id,
            'evacuation_event_id' => $event->id,
            'displacement_type' => 'outside_center',
            'members' => [
                [
                    'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'sex' => 'male',
                    'date_of_birth' => '1990-01-01', 'contact_number' => '09171234567',
                    'is_head_of_family' => true,
                ],
            ],
        ]);

        $update->assertRedirect(route('families.index'));
        $family->refresh();
        $this->assertNull($family->sync_error, 'editing should clear the stale sync_error so the card stops showing it as permanently failed');
        $this->assertSame('09171234567', $family->evacuees()->first()->contact_number);

        // And it now actually syncs successfully.
        Http::fake(['*/families/register' => Http::response(['data' => ['id' => 555]], 201)]);
        $this->post(route('families.sync'));
        $this->assertNotNull($family->refresh()->synced_at);
    }

    /**
     * Scenario 2 from the bug report: the family points at a LOCAL cache
     * row for an event ("TYPHOON NGA") whose remote_id was deleted on the
     * central server during demo-data cleanup, long before this device's
     * cache was refreshed. Local exists: validation passes fine (the local
     * row is still there); only a real sync attempt reveals it's dead.
     * Confirms re-selecting a different, currently-valid event fixes it.
     */
    public function test_editing_re_points_a_family_at_a_currently_valid_event_after_the_old_one_was_deleted_upstream(): void
    {
        $this->login();
        $barangay = Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        // Still cached locally (this device hasn't refreshed since), but its
        // remote_id (99) no longer exists on the central server.
        $deletedEvent = EvacuationEvent::create(['remote_id' => 99, 'name' => 'TYPHOON NGA', 'event_type' => 'typhoon', 'status' => 'active']);
        $currentEvent = EvacuationEvent::create(['remote_id' => 2, 'name' => 'Typhoon Current', 'event_type' => 'typhoon', 'status' => 'active']);
        $family = $this->baseFamily($barangay, $deletedEvent, 'The selected evacuation event id is invalid.');

        $update = $this->put(route('families.update', $family), [
            'barangay_id' => $barangay->id,
            'evacuation_event_id' => $currentEvent->id,
            'displacement_type' => 'outside_center',
            'members' => [
                [
                    'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'sex' => 'male',
                    'date_of_birth' => '1990-01-01', 'contact_number' => '09171234567',
                    'is_head_of_family' => true,
                ],
            ],
        ]);

        $update->assertRedirect(route('families.index'));
        $family->refresh();
        $this->assertSame($currentEvent->id, $family->evacuation_event_id);
        $this->assertNull($family->sync_error);

        Http::fake(['*/families/register' => Http::response(['data' => ['id' => 556]], 201)]);
        $this->post(route('families.sync'));
        $this->assertNotNull($family->refresh()->synced_at);
    }

    /**
     * Guards against a regression in the create form itself: _form.blade.php
     * now branches on an $isEditing flag derived from $family, which
     * create() always passes as null -- confirms that null case still
     * renders a plain blank form rather than erroring on an undefined
     * variable or a null property access.
     */
    public function test_plain_create_form_still_renders_with_no_family_prefill(): void
    {
        $this->login();
        Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);

        $response = $this->get(route('families.create'));

        $response->assertOk();
        $response->assertSee('Register a family');
        $response->assertSee('Save family (offline)');
        $response->assertDontSee('Edit pending registration');
    }

    public function test_the_form_visibly_marks_which_top_level_fields_are_required(): void
    {
        $this->login();
        Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);

        $response = $this->get(route('families.create'));

        $response->assertOk();
        $response->assertSee('Fields marked with', false);
        $response->assertSee('Barangay <span class="text-red-500">*</span>', false);
        $response->assertSee('Disaster event <span class="text-red-500">*</span>', false);
        $response->assertSee('Evacuation center <span class="text-red-500">*</span>', false);
    }

    /**
     * Household member fields (first/last name, sex, date of birth) are
     * injected client-side by app.js's memberRowHtml() -- PHPUnit's test
     * client never executes JS, so their own required-markers can only be
     * confirmed by inspecting the built bundle's source directly rather
     * than a rendered page response.
     */
    public function test_the_built_js_bundle_marks_required_member_fields(): void
    {
        $manifestPath = public_path('build/manifest.json');
        if (! file_exists($manifestPath)) {
            $this->markTestSkipped('Frontend assets not built (run `npm run build`) -- nothing to inspect yet.');
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $bundlePath = public_path('build/'.$manifest['resources/js/app.js']['file']);
        $js = file_get_contents($bundlePath);

        $this->assertStringContainsString('First name *', $js);
        $this->assertStringContainsString('Last name *', $js);
        $this->assertStringContainsString('Date of birth', $js);
        $this->assertStringContainsString('Middle name (optional)', $js);
        $this->assertStringContainsString('Contact number (optional)', $js);
    }

    public function test_edit_form_shows_edit_specific_labels_and_existing_member_data(): void
    {
        $this->login();
        $barangay = Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $family = $this->baseFamily($barangay, $event);

        $response = $this->get(route('families.edit', $family));

        $response->assertOk();
        $response->assertSee('Edit pending registration');
        $response->assertSee('Save changes (offline)');
        $response->assertSee('Juan');
        $response->assertSee('Dela Cruz');
    }

    public function test_update_is_refused_for_an_already_synced_family(): void
    {
        $this->login();
        $barangay = Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $family = $this->baseFamily($barangay, $event);
        $family->update(['synced_at' => now(), 'remote_id' => 999]);
        $originalEventId = $family->evacuation_event_id;

        $otherEvent = EvacuationEvent::create(['remote_id' => 2, 'name' => 'Typhoon B', 'event_type' => 'typhoon', 'status' => 'active']);
        $this->put(route('families.update', $family), [
            'barangay_id' => $barangay->id,
            'evacuation_event_id' => $otherEvent->id,
            'displacement_type' => 'outside_center',
            'members' => [
                ['first_name' => 'X', 'last_name' => 'Y', 'sex' => 'male', 'date_of_birth' => '1990-01-01', 'is_head_of_family' => true],
            ],
        ]);

        $this->assertSame($originalEventId, $family->refresh()->evacuation_event_id);
    }
}
