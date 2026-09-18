<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\Family;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReferenceDataPruneTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_prunes_removed_centers_but_protects_ones_referenced_by_local_families(): void
    {
        // Seed state as if this device logged in weeks ago: barangay 1,
        // event 1, and THREE centers -- remote_id 1 (will be dropped by the
        // server, simulating a delete-and-reseed), remote_id 2 (still
        // current, name will change), remote_id 99 (also dropped, but a
        // local unsynced family still points at it -- must survive).
        $barangay = Barangay::create(['remote_id' => 1, 'name' => 'Old Barangay Name']);
        EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);

        $droppedCenter = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => '[SAMPLE] Center', 'status' => 'active']);
        $survivingCenter = EvacuationCenter::create(['remote_id' => 2, 'barangay_remote_id' => 1, 'name' => 'Old Name', 'status' => 'active']);
        $referencedButDroppedCenter = EvacuationCenter::create(['remote_id' => 99, 'barangay_remote_id' => 1, 'name' => 'Still In Use Locally', 'status' => 'active']);

        // A local, not-yet-synced family still references the "referenced
        // but dropped" center by its LOCAL id -- this must NOT be deleted,
        // or the family's toSyncPayload() would silently lose its center.
        Family::create([
            'barangay_id' => $barangay->id,
            'evacuation_event_id' => EvacuationEvent::first()->id,
            'evacuation_center_id' => $referencedButDroppedCenter->id,
            'displacement_type' => 'inside_center',
        ]);

        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official',
            'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);

        // Simulate the central server's CURRENT state after the real rename:
        // remote_id 1 and 99 are gone, remote_id 2 renamed, remote_id 3 is
        // a brand new center.
        Http::fake([
            '*/barangays' => Http::response(['data' => [['id' => 1, 'name' => 'Old Barangay Name']]]),
            '*/evacuation-events' => Http::response(['data' => [['id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']]]),
            '*/evacuation-centers' => Http::response(['data' => [
                ['id' => 2, 'barangay_id' => 1, 'name' => 'Barangay Hall (renamed)', 'status' => 'active'],
                ['id' => 3, 'barangay_id' => 1, 'name' => 'Covered Court', 'status' => 'active'],
            ]]),
        ]);

        $this->post(route('reference-data.refresh'));

        $remaining = EvacuationCenter::orderBy('remote_id')->get(['remote_id', 'name'])->toArray();

        $this->assertDatabaseMissing('evacuation_centers', ['id' => $droppedCenter->id]);
        $this->assertDatabaseHas('evacuation_centers', ['id' => $referencedButDroppedCenter->id, 'remote_id' => 99]);
        $this->assertDatabaseHas('evacuation_centers', ['remote_id' => 2, 'name' => 'Barangay Hall (renamed)']);
        $this->assertDatabaseHas('evacuation_centers', ['remote_id' => 3, 'name' => 'Covered Court']);
        $this->assertCount(3, $remaining, 'expected: surviving renamed (2), new (3), and protected-by-family (99) -- dropped (1) gone');
    }

    /**
     * The real incident this covers: a device with a pending/synced EC
     * Board entry (not a Family) hit a genuine SQLite "FOREIGN KEY
     * constraint failed" when refreshReferenceData() tried to prune an
     * event/center that entry still referenced -- ec_board_entries.
     * evacuation_event_id/evacuation_center_id are real foreign keys too,
     * but pruneStale()'s guard only ever checked families. The exception
     * (a QueryException, which extends RuntimeException) got silently
     * caught by login()'s try/catch, aborting the rest of the refresh
     * with no visible error -- evacuation_centers was left 54 stale rows
     * out of date against a real 39, discovered only by the household/
     * center dropdowns pointing at the wrong record entirely.
     */
    public function test_refresh_protects_an_event_and_center_still_referenced_by_a_local_ec_board_entry(): void
    {
        $barangay = Barangay::create(['remote_id' => 1, 'name' => 'Old Barangay Name']);
        $droppedEvent = EvacuationEvent::create(['remote_id' => 13, 'name' => 'Typhoon Bagwis', 'event_type' => 'typhoon', 'status' => 'active']);
        $droppedCenter = EvacuationCenter::create(['remote_id' => 5, 'barangay_remote_id' => 1, 'name' => 'Stale Center', 'status' => 'active']);

        // A local EC Board entry (not a Family) still references both by
        // their LOCAL ids -- this must protect them from pruneStale(),
        // the same principle already applied to Family, now covering this
        // second real FK relationship.
        EcBoardEntry::create([
            'evacuation_center_id' => $droppedCenter->id,
            'evacuation_event_id' => $droppedEvent->id,
            'sex' => 'male',
            'age_bracket' => 'adult',
            'new_household_head_name' => 'Juan Dela Cruz',
        ]);

        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official',
            'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);

        // Simulates switching backends (or the event simply closing
        // upstream): the fresh fetch no longer includes remote_id 13/5 at
        // all, replacing them with entirely different real records.
        Http::fake([
            '*/barangays' => Http::response(['data' => [['id' => 1, 'name' => 'Old Barangay Name']]]),
            '*/evacuation-events' => Http::response(['data' => [
                ['id' => 7, 'name' => 'Tropical Storm Amang', 'event_type' => 'typhoon', 'status' => 'active'],
            ]]),
            '*/evacuation-centers' => Http::response(['data' => [
                ['id' => 27, 'barangay_id' => 1, 'name' => 'Binatagan Covered Court', 'status' => 'active'],
            ]]),
        ]);

        // Before the fix, this threw a QueryException (FK violation) that
        // aborted the request with a 500 -- redirecting back with no
        // error (the normal success path) alone proves the crash is gone.
        $response = $this->post(route('reference-data.refresh'));
        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // The still-referenced stale event/center survive (protected,
        // exactly like the Family case) rather than crashing the whole
        // refresh...
        $this->assertDatabaseHas('evacuation_events', ['id' => $droppedEvent->id, 'remote_id' => 13]);
        $this->assertDatabaseHas('evacuation_centers', ['id' => $droppedCenter->id, 'remote_id' => 5]);

        // ...and critically, the refresh continues past them instead of
        // aborting -- the new real event/center from THIS fetch are
        // actually present, which is exactly what a real device needs to
        // see the real Binatagan Covered Court instead of a stale,
        // mismatched center.
        $this->assertDatabaseHas('evacuation_events', ['remote_id' => 7, 'name' => 'Tropical Storm Amang']);
        $this->assertDatabaseHas('evacuation_centers', ['remote_id' => 27, 'name' => 'Binatagan Covered Court']);
    }

    public function test_refresh_does_not_wipe_cache_on_empty_response(): void
    {
        EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Should Survive', 'status' => 'active']);

        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official',
            'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);

        // Simulate a transient/malformed response: server returns an empty
        // centers list. This must NOT be treated as "the server has zero
        // centers now" and wipe the local cache.
        Http::fake([
            '*/barangays' => Http::response(['data' => []]),
            '*/evacuation-events' => Http::response(['data' => []]),
            '*/evacuation-centers' => Http::response(['data' => []]),
        ]);

        $this->post(route('reference-data.refresh'));

        $this->assertDatabaseHas('evacuation_centers', ['remote_id' => 1, 'name' => 'Should Survive']);
    }

    public function test_refresh_reference_data_never_calls_the_per_center_quick_count_endpoint(): void
    {
        // The EC Board's "as of last sync" breakdown is fetched ON DEMAND,
        // per center+event, only when that center's detail page is opened
        // (see EvacuationCenterController::show()) -- confirms the general
        // reference-data refresh (login, or the manual "Refresh reference
        // data" action) never touches quick-count for any cached center,
        // which would mean N requests for centers nobody is even viewing.
        EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Barangay Hall', 'status' => 'active']);

        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official',
            'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);

        Http::fake([
            '*/barangays' => Http::response(['data' => []]),
            '*/evacuation-events' => Http::response(['data' => []]),
            '*/evacuation-centers' => Http::response(['data' => []]),
        ]);

        $this->post(route('reference-data.refresh'));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'quick-count'));
    }

    public function test_registration_form_excludes_closed_centers(): void
    {
        EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Active Center', 'status' => 'active']);
        EvacuationCenter::create(['remote_id' => 2, 'barangay_remote_id' => 1, 'name' => 'Closed Center', 'status' => 'closed']);
        EvacuationCenter::create(['remote_id' => 3, 'barangay_remote_id' => 1, 'name' => 'Full Center', 'status' => 'full']);
        EvacuationCenter::create(['remote_id' => 4, 'barangay_remote_id' => 1, 'name' => 'Standby Center', 'status' => 'on_standby']);

        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official',
            'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);

        $response = $this->get(route('families.create'));

        $response->assertOk();
        $response->assertSee('Active Center');
        $response->assertSee('Full Center');
        $response->assertSee('Standby Center');
        $response->assertDontSee('Closed Center');
    }
}
