<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\EvacuationCenterQuickCount;
use App\Models\EvacuationCenterSectoralSnapshot;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers the sectoral/4Ps "Last known" vs "Pending" dual view -- see
 * EvacuationCenterSectoralSnapshot's own docblock for why this is a
 * separate model from EvacuationCenterQuickCount (the confirmed real gap:
 * before this, an in-progress local edit had nowhere to preserve the
 * previously-synced snapshot to show alongside it, unlike the age/sex
 * breakdown's own long-established EvacuationCenterBreakdown/EcBoardEntry
 * split). Also covers Edit/Delete for a pending sectoral edit, mirroring
 * EcBoardEntryManagementTest's own synced-record-is-immutable convention.
 */
class SectoralDualViewTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Barangay, 1: EvacuationEvent, 2: EvacuationCenter} */
    private function seedBase(): array
    {
        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official',
            'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);
        $barangay = Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Barangay Hall', 'status' => 'active']);

        return [$barangay, $event, $center];
    }

    public function test_the_sectoral_refresh_endpoint_fetches_and_caches_the_last_known_snapshot(): void
    {
        [, $event, $center] = $this->seedBase();

        Http::fake([
            '*/evacuation-centers/1/quick-count*' => Http::response(['data' => [
                'beneficiaries_4ps' => 7,
                'sectoral_groups' => [
                    ['sectoral_group' => 'pwd', 'male_count' => 2, 'female_count' => 1],
                ],
                'updated_by_name' => 'Central Staff',
                'updated_at' => '2026-09-01T10:00:00Z',
            ]]),
        ]);

        $response = $this->get(route('evacuation-centers.sectoral-refresh', $center).'?event='.$event->id);

        $response->assertOk();
        $response->assertSee('7');
        $response->assertSee('Central Staff');

        $this->assertDatabaseHas('evacuation_center_sectoral_snapshots', [
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'beneficiaries_4ps' => 7,
            'updated_by_name' => 'Central Staff',
        ]);
    }

    /**
     * The exact real gap this whole feature closes: editing sectoral
     * figures used to overwrite the one row that also stood in for
     * "current state", so the previously-synced values had nowhere to
     * survive once an edit was in progress. Seeds genuinely DIFFERENT
     * values on each side to prove neither is silently reading the
     * other's data.
     */
    public function test_the_last_known_and_pending_sectoral_views_stay_separate_not_merged(): void
    {
        [, $event, $center] = $this->seedBase();

        EvacuationCenterSectoralSnapshot::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'beneficiaries_4ps' => 3,
            'sectoral_groups' => [['sectoral_group' => 'pwd', 'male_count' => 1, 'female_count' => 0]],
        ]);

        $quickCount = EvacuationCenterQuickCount::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'beneficiaries_4ps' => 99,
        ]);
        $quickCount->sectoralGroups()->create(['sectoral_group' => 'pwd', 'male_count' => 9, 'female_count' => 9]);

        $page = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));

        $page->assertOk();
        $page->assertSee('3'); // last known 4ps
        $page->assertSee('99'); // pending 4ps
        $page->assertSee('Saved on this device, not yet synced.');
    }

    public function test_the_edit_form_prefills_from_the_pending_edit_over_last_known_when_both_exist(): void
    {
        [, $event, $center] = $this->seedBase();

        EvacuationCenterSectoralSnapshot::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'beneficiaries_4ps' => 3,
            'sectoral_groups' => [],
        ]);
        EvacuationCenterQuickCount::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'beneficiaries_4ps' => 42,
        ]);

        $page = $this->get(route('evacuation-centers.sectoral.edit', $center).'?event='.$event->id);

        $page->assertOk();
        $page->assertSee('value="42"', false);
        $page->assertDontSee('value="3"', false);
    }

    public function test_the_edit_form_prefills_from_last_known_when_no_pending_edit_exists(): void
    {
        [, $event, $center] = $this->seedBase();

        EvacuationCenterSectoralSnapshot::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'beneficiaries_4ps' => 15,
            'sectoral_groups' => [],
        ]);

        $page = $this->get(route('evacuation-centers.sectoral.edit', $center).'?event='.$event->id);

        $page->assertOk();
        $page->assertSee('value="15"', false);
    }

    /**
     * An already-synced leftover row (kept around, never deleted after a
     * successful sync) must NOT be treated as "the pending draft to
     * resume" -- it should fall through to Last known instead, same as
     * if no pending row existed at all.
     */
    public function test_an_already_synced_quick_count_row_does_not_count_as_the_pending_draft(): void
    {
        [, $event, $center] = $this->seedBase();

        EvacuationCenterSectoralSnapshot::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'beneficiaries_4ps' => 15,
            'sectoral_groups' => [],
        ]);
        EvacuationCenterQuickCount::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'beneficiaries_4ps' => 42,
            'synced_at' => now(),
        ]);

        $page = $this->get(route('evacuation-centers.sectoral.edit', $center).'?event='.$event->id);

        $page->assertOk();
        $page->assertSee('value="15"', false);
    }

    public function test_delete_removes_a_pending_sectoral_edit(): void
    {
        [, $event, $center] = $this->seedBase();
        $quickCount = EvacuationCenterQuickCount::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'beneficiaries_4ps' => 5,
        ]);

        $response = $this->delete(route('quick-counts.destroy', $quickCount));

        $response->assertRedirect(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));
        $this->assertDatabaseMissing('evacuation_center_quick_counts', ['id' => $quickCount->id]);
    }

    public function test_delete_is_refused_for_an_already_synced_sectoral_edit(): void
    {
        [, $event, $center] = $this->seedBase();
        $quickCount = EvacuationCenterQuickCount::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'beneficiaries_4ps' => 5,
            'synced_at' => now(),
        ]);

        $this->delete(route('quick-counts.destroy', $quickCount));

        $this->assertDatabaseHas('evacuation_center_quick_counts', ['id' => $quickCount->id]);
    }

    public function test_deleting_a_pending_sectoral_edit_never_touches_the_last_known_snapshot(): void
    {
        [, $event, $center] = $this->seedBase();
        $snapshot = EvacuationCenterSectoralSnapshot::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'beneficiaries_4ps' => 3,
            'sectoral_groups' => [],
        ]);
        $quickCount = EvacuationCenterQuickCount::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'beneficiaries_4ps' => 99,
        ]);

        $this->delete(route('quick-counts.destroy', $quickCount));

        $this->assertDatabaseHas('evacuation_center_sectoral_snapshots', ['id' => $snapshot->id, 'beneficiaries_4ps' => 3]);
    }

    public function test_the_pending_card_shows_a_delete_button_only_when_something_is_actually_pending(): void
    {
        [, $event, $center] = $this->seedBase();

        $emptyPage = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));
        $emptyPage->assertOk();
        $emptyPage->assertDontSee('Delete pending edit');
        $emptyPage->assertSee('Nothing pending');

        EvacuationCenterQuickCount::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'beneficiaries_4ps' => 1,
        ]);

        $pendingPage = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));
        $pendingPage->assertSee('Delete pending edit');
    }
}
