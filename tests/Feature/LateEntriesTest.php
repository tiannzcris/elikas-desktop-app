<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\EvacuationCenterSectoralSnapshot;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\ServerSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Late entries: registered after a barangay's latest final count; a validated count never changes. */
class LateEntriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_board_keeps_and_shows_the_centers_late_entries(): void
    {
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $event = EvacuationEvent::create(['remote_id' => 79, 'name' => 'Typhoon', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 7, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'active']);
        Http::fake(['*/evacuation-centers/7/quick-count*' => Http::response(['data' => [
            'families_cumulative' => 14, 'families_now' => 12, 'persons_cumulative' => 52, 'persons_now' => 48,
            'age_groups' => [], 'sectoral_groups' => [], 'beneficiaries_4ps' => 3, 'late_entries' => 3,
        ]])]);

        $this->get(route('evacuation-centers.board-refresh', ['center' => $center, 'event' => $event->id]))->assertOk();

        $this->assertSame(3, EvacuationCenterSectoralSnapshot::first()->late_entries);
        $this->get(route('evacuation-centers.ec-board', $center))
            ->assertSee('data-late-entries', false)->assertSee('3 late entries, not yet in a final count');
    }

    public function test_cswd_sees_late_entries_per_barangay_and_the_validated_count_stays(): void
    {
        LocalAuth::create(['remote_user_id' => 2, 'name' => 'C', 'email' => 'c@x', 'role' => 'cswd_personnel',
            'permissions' => ['operations.access', 'scope.all_barangays', 'count_submissions.review'], 'api_token' => 't', 'logged_in_at' => now()]);
        ServerSnapshot::keep('final_counts', ['evacuation_event' => ['id' => 79, 'name' => 'Typhoon'], 'late_entries_total' => 1, 'barangays' => [
            ['barangay' => ['id' => 4, 'name' => 'Bacong'], 'has_official_account' => true, 'computed' => ['families' => 111],
                'current_submission' => ['status' => 'validated', 'families' => 110, 'persons' => 410], 'validated_need' => ['families' => 110],
                'late_entries' => ['families' => 1, 'persons' => 4, 'total' => 1, 'removed_families' => 0]],
        ]]);

        $this->get(route('final-counts.index'))->assertOk()
            ->assertSeeInOrder(['Late entries', '1', 'not yet in a final count'])
            ->assertSee('A validated count never changes.')
            ->assertSeeInOrder(['Bacong', 'Validated', '110', '1 family', '110']);
    }
}
