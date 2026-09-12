<?php

namespace Tests\Feature;

use App\Models\Barangay;
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
