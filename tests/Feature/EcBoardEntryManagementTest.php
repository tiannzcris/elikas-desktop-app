<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationCenterBreakdown;
use App\Models\EvacuationEvent;
use App\Models\Evacuee;
use App\Models\Family;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EcBoardEntryManagementTest extends TestCase
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

    public function test_adding_an_evacuee_offline_shows_immediately_in_the_pending_breakdown_without_connectivity(): void
    {
        [$barangay, $event, $center] = $this->seedBase();

        $response = $this->post(route('ec-board-entries.store', $center), [
            'evacuation_event_id' => $event->id,
            'sex' => 'male',
            'age_bracket' => 'adult',
            'household_type' => 'new',
            'new_household_head_name' => 'Juan Dela Cruz',
        ]);

        $response->assertRedirect(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));

        // No HTTP call was made at all -- this is purely a local save, so
        // nothing here required connectivity.
        $this->assertDatabaseHas('ec_board_entries', [
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'sex' => 'male',
            'age_bracket' => 'adult',
            'new_household_head_name' => 'Juan Dela Cruz',
            'synced_at' => null,
        ]);

        // The page itself makes no live network call at all (see
        // EvacuationCenterController::ecBoard()'s own docblock for why --
        // that used to happen here and is exactly the bug that once broke
        // this page's styling while offline) -- no Http::fake() needed to
        // keep this test fast or deterministic.
        $page = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));
        $page->assertOk();
        $page->assertSee('As of last sync');
        $page->assertSee('Added on this device (pending sync)');
        $page->assertSee('Juan Dela Cruz');
    }

    public function test_the_last_known_and_pending_breakdowns_stay_separate_not_merged(): void
    {
        [$barangay, $event, $center] = $this->seedBase();

        // A server-side snapshot already exists for this center+event...
        EvacuationCenterBreakdown::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'sex' => 'male',
            'age_bracket' => 'adult',
            'count' => 42,
        ]);

        // ...and this device has since added one more, not yet synced.
        EcBoardEntry::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'sex' => 'male',
            'age_bracket' => 'adult',
            'new_household_head_name' => 'Maria Santos',
        ]);

        // The page itself makes no live network call (see ecBoard()'s own
        // docblock) -- the pre-seeded "42" snapshot below is read straight
        // from the local cache, untouched by this request. The live
        // refresh endpoint has its own dedicated test below.
        $page = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));
        $page->assertOk();

        // Scoped, section-by-section checks rather than a bare global
        // substring search: "42" is not actually unique on the page --
        // the shared layout's header renders a box-shadow with
        // "rgba(15, 23, 42, ...)" on every single page, before
        // @yield('content') even starts (see layouts/app.blade.php), so a
        // plain strpos($content, '42') finds THAT "42" first, not
        // anything in either breakdown table. Slicing the response into
        // the two card sections (bounded by their own headings, and by
        // the next heading -- "Add evacuee" -- after the second card)
        // avoids that collision entirely. Matching '>42<' (not a bare
        // '42') also correctly allows for 42 legitimately appearing MORE
        // than once inside a correctly-rendering last-known table (once
        // in the 'adult' bracket row, again in the grand-total row) --
        // this test's earlier "must appear exactly once" assumption was
        // its own bug, not a real constraint on the view.
        $content = $page->getContent();
        $lastSyncPos = strpos($content, 'As of last sync');
        $pendingSectionPos = strpos($content, 'Added on this device (pending sync)');
        $addEvacueeHeadingPos = strpos($content, 'Add evacuee', $pendingSectionPos);

        $this->assertNotFalse($lastSyncPos);
        $this->assertNotFalse($pendingSectionPos);
        $this->assertNotFalse($addEvacueeHeadingPos);
        $this->assertTrue($lastSyncPos < $pendingSectionPos, 'the "As of last sync" section must render before the "pending" section');

        $lastSyncSectionHtml = substr($content, $lastSyncPos, $pendingSectionPos - $lastSyncPos);
        $pendingSectionHtml = substr($content, $pendingSectionPos, $addEvacueeHeadingPos - $pendingSectionPos);

        $this->assertStringContainsString('>42<', $lastSyncSectionHtml, 'the untouched last-known count (42) must render inside the "As of last sync" section');
        $this->assertStringNotContainsString('>42<', $pendingSectionHtml, 'the pending section must show only this device\'s own 1 pending entry -- never merged with, or bumped by, the last-known 42');
    }

    /**
     * The breakdown fetch moved OFF the EC Board page's own synchronous
     * render (see ecBoard()'s docblock) and into a client-side fetch()
     * that hits this dedicated endpoint after the page has already
     * loaded. This test calls that endpoint directly -- exactly what the
     * page's own JS does -- rather than expecting the page load itself to
     * trigger it (it deliberately no longer does; that was the bug).
     */
    public function test_the_breakdown_refresh_endpoint_fetches_and_caches_the_live_breakdown(): void
    {
        [$barangay, $event, $center] = $this->seedBase();

        Http::fake([
            '*/evacuation-centers/1/quick-count*' => Http::response(['data' => [
                'age_groups' => [
                    ['age_bracket' => 'adult', 'male_count' => 3, 'female_count' => 2],
                    ['age_bracket' => 'infant', 'male_count' => 0, 'female_count' => 0],
                    ['age_bracket' => 'toddler', 'male_count' => 0, 'female_count' => 0],
                    ['age_bracket' => 'preschooler', 'male_count' => 0, 'female_count' => 0],
                    ['age_bracket' => 'school_age', 'male_count' => 0, 'female_count' => 0],
                    ['age_bracket' => 'teenage', 'male_count' => 0, 'female_count' => 0],
                    ['age_bracket' => 'senior_citizen', 'male_count' => 0, 'female_count' => 0],
                    ['age_bracket' => 'unclassified', 'male_count' => 0, 'female_count' => 0, 'total_count' => 0],
                ],
            ]]),
        ]);

        $response = $this->get(route('evacuation-centers.breakdown-refresh', $center).'?event='.$event->id);
        $response->assertOk();
        $response->assertSee('Adult');

        // Fetched from the REAL per-center+event endpoint, with the
        // event's remote id as a query param -- not a bulk "all centers"
        // call.
        Http::assertSent(function ($request) use ($event) {
            return str_contains($request->url(), '/evacuation-centers/1/quick-count')
                && str_contains($request->url(), 'evacuation_event_id='.$event->remote_id);
        });

        // The fetched figures are cached locally, so the display and any
        // later offline visit can read them straight from this table.
        $this->assertDatabaseHas('evacuation_center_breakdowns', [
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'sex' => 'male',
            'age_bracket' => 'adult',
            'count' => 3,
        ]);
        $this->assertDatabaseHas('evacuation_center_breakdowns', [
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'sex' => 'female',
            'age_bracket' => 'adult',
            'count' => 2,
        ]);
    }

    public function test_opening_the_ec_board_page_makes_no_live_network_call_at_all(): void
    {
        [$barangay, $event, $center] = $this->seedBase();

        // Faked with NO matching pattern at all -- Http::fake() with an
        // empty array still activates request recording, so
        // Http::assertNothingSent() below is meaningful: any real request
        // would be recorded (and, since nothing matches, would otherwise
        // attempt a real network call and likely fail/hang in a test
        // environment).
        Http::fake();

        $page = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));

        $page->assertOk();
        Http::assertNothingSent();
    }

    public function test_the_households_refresh_endpoint_appends_remote_only_households_not_already_local(): void
    {
        [$barangay, $event, $center] = $this->seedBase();

        // Already known locally (synced, remote_id set) -- must NOT be
        // duplicated in the live-fetched result.
        $localHousehold = Family::create([
            'barangay_id' => $barangay->id, 'evacuation_event_id' => $event->id,
            'evacuation_center_id' => $center->id, 'displacement_type' => 'inside_center',
            'synced_at' => now(), 'remote_id' => 10,
        ]);
        Evacuee::create([
            'family_id' => $localHousehold->id, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
            'sex' => 'male', 'date_of_birth' => '1990-01-01', 'is_head_of_family' => true,
        ]);

        Http::fake([
            '*/evacuation-centers/1/families*' => Http::response(['data' => [
                ['id' => 10, 'head_of_family' => ['full_name' => 'Juan Dela Cruz']],
                ['id' => 11, 'name' => 'Reyes Household', 'head_of_family' => null],
            ]]),
        ]);

        $response = $this->get(route('evacuation-centers.households-refresh', $center).'?event='.$event->id);

        $response->assertOk();
        $response->assertJson([
            ['value' => 'remote-11', 'label' => 'Reyes Household'],
        ]);
        // The already-local household (remote id 10) must not appear --
        // it's already in the form's server-rendered options.
        $response->assertJsonMissing(['value' => 'remote-10']);
    }

    public function test_a_household_picked_from_the_live_remote_list_syncs_using_its_remote_family_id_directly(): void
    {
        [$barangay, $event, $center] = $this->seedBase();

        $entry = EcBoardEntry::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'sex' => 'male',
            'age_bracket' => 'adult',
            'existing_household_remote_id' => 99,
            'new_household_head_name' => 'Remote Household Head', // display snapshot only
        ]);

        Http::fake(['*/evacuation-centers/*/evacuees' => Http::response(['data' => ['id' => 200, 'evacuee_id' => 321]], 201)]);

        $this->post(route('families.sync'));

        Http::assertSent(fn ($request) => $request['household_mode'] === 'existing'
            && $request['family_id'] === 99
            && $request['family_name'] === null);

        $this->assertSame(321, $entry->refresh()->remote_id);
    }

    public function test_reference_data_refresh_does_not_perform_any_on_demand_breakdown_fetch(): void
    {
        [$barangay, $event, $center] = $this->seedBase();

        Http::fake([
            '*/barangays' => Http::response(['data' => []]),
            '*/evacuation-events' => Http::response(['data' => []]),
            '*/evacuation-centers' => Http::response(['data' => []]),
        ]);

        $this->post(route('reference-data.refresh'));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'quick-count'));
    }

    public function test_syncing_a_new_household_entry_sends_family_name_and_barangay_id(): void
    {
        [$barangay, $event, $center] = $this->seedBase();

        $entry = EcBoardEntry::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'sex' => 'female',
            'age_bracket' => 'school_age',
            'new_household_head_name' => 'Ana Reyes',
        ]);

        Http::fake(['*/evacuation-centers/*/evacuees' => Http::response(['data' => ['id' => 100, 'evacuee_id' => 777]], 201)]);

        $this->post(route('families.sync'));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/evacuation-centers/1/evacuees')
                && $request['household_mode'] === 'new'
                && $request['family_name'] === 'Ana Reyes'
                // The center's own barangay_remote_id, since the real
                // endpoint requires it to create a brand-new Family record
                // and nothing else local carries this new household's
                // barangay.
                && $request['barangay_id'] === 1
                && $request['family_id'] === null;
        });
    }

    public function test_syncing_an_existing_household_entry_sends_family_id_not_the_old_wrong_field_name(): void
    {
        [$barangay, $event, $center] = $this->seedBase();

        $household = Family::create([
            'barangay_id' => $barangay->id, 'evacuation_event_id' => $event->id,
            'evacuation_center_id' => $center->id, 'displacement_type' => 'inside_center',
            'synced_at' => now(), 'remote_id' => 42,
        ]);

        $entry = EcBoardEntry::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'sex' => 'male',
            'age_bracket' => 'teenage',
            'household_family_local_id' => $household->id,
        ]);

        Http::fake(['*/evacuation-centers/*/evacuees' => Http::response(['data' => ['id' => 100, 'evacuee_id' => 888]], 201)]);

        $this->post(route('families.sync'));

        Http::assertSent(function ($request) {
            return $request['household_mode'] === 'existing'
                && $request['family_id'] === 42
                && $request['family_name'] === null
                && $request['barangay_id'] === null
                && ! isset($request['household_family_id']); // the old, wrong field name must be gone
        });
    }

    public function test_sync_stores_the_responses_evacuee_id_as_remote_id_not_the_familys_own_id(): void
    {
        [$barangay, $event, $center] = $this->seedBase();

        $entry = EcBoardEntry::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'sex' => 'female',
            'age_bracket' => 'preschooler',
            'new_household_head_name' => 'Ana Reyes',
        ]);

        // The real response wraps a FamilyResource under data.id (100) --
        // a value that must NEVER end up as this entry's remote_id -- plus
        // a separate top-level data.evacuee_id (777) for the evacuee that
        // was actually just created, which is what should be stored.
        Http::fake(['*/evacuation-centers/*/evacuees' => Http::response(['data' => ['id' => 100, 'evacuee_id' => 777]], 201)]);

        $response = $this->post(route('families.sync'));

        $response->assertSessionHas('status', '1 record(s) synced successfully.');

        $entry->refresh();
        $this->assertNotNull($entry->synced_at);
        $this->assertSame(777, $entry->remote_id);
        $this->assertNotSame(100, $entry->remote_id);
        $this->assertNull($entry->sync_error);
    }

    public function test_sync_syncs_both_pending_families_and_pending_evacuee_entries_in_one_run(): void
    {
        [$barangay, $event, $center] = $this->seedBase();

        $family = Family::create([
            'barangay_id' => $barangay->id, 'evacuation_event_id' => $event->id, 'displacement_type' => 'outside_center',
        ]);
        Evacuee::create([
            'family_id' => $family->id, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
            'sex' => 'male', 'date_of_birth' => '1990-01-01', 'is_head_of_family' => true,
        ]);

        $entry = EcBoardEntry::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'sex' => 'male',
            'age_bracket' => 'infant',
            'new_household_head_name' => 'Baby Cruz',
        ]);

        Http::fake([
            '*/families/register' => Http::response(['data' => ['id' => 555]], 201),
            '*/evacuation-centers/*/evacuees' => Http::response(['data' => ['id' => 556, 'evacuee_id' => 777]], 201),
        ]);

        $response = $this->post(route('families.sync'));

        $response->assertSessionHas('status', '2 record(s) synced successfully.');
        $this->assertNotNull($family->refresh()->synced_at);
        $this->assertNotNull($entry->refresh()->synced_at);
        $this->assertSame(777, $entry->remote_id);
    }

    public function test_an_existing_household_entry_fails_to_sync_while_its_household_has_not_synced_yet(): void
    {
        [$barangay, $event, $center] = $this->seedBase();

        $household = Family::create([
            'barangay_id' => $barangay->id, 'evacuation_event_id' => $event->id,
            'evacuation_center_id' => $center->id, 'displacement_type' => 'inside_center',
        ]);
        Evacuee::create([
            'family_id' => $household->id, 'first_name' => 'Juan', 'last_name' => 'Dela Cruz',
            'sex' => 'male', 'date_of_birth' => '1990-01-01', 'is_head_of_family' => true,
        ]);

        $entry = EcBoardEntry::create([
            'evacuation_center_id' => $center->id,
            'evacuation_event_id' => $event->id,
            'sex' => 'male',
            'age_bracket' => 'adult',
            'household_family_local_id' => $household->id,
        ]);

        // The household's own registration fails to sync this round --
        // it stays queued, not yet known to the central server at all.
        Http::fake(['*/families/register' => Http::response(['message' => 'Rejected.'], 422)]);

        $this->post(route('families.sync'));

        $entry->refresh();
        $this->assertNull($entry->synced_at);
        $this->assertStringContainsString('has not synced yet', $entry->sync_error);
    }

    public function test_delete_is_refused_for_an_already_synced_entry(): void
    {
        [$barangay, $event, $center] = $this->seedBase();
        $entry = EcBoardEntry::create([
            'evacuation_center_id' => $center->id, 'evacuation_event_id' => $event->id,
            'sex' => 'male', 'age_bracket' => 'adult', 'new_household_head_name' => 'Synced Head',
            'synced_at' => now(), 'remote_id' => 999,
        ]);

        $this->delete(route('ec-board-entries.destroy', $entry));

        $this->assertDatabaseHas('ec_board_entries', ['id' => $entry->id]);
    }

    public function test_edit_is_refused_for_an_already_synced_entry(): void
    {
        [$barangay, $event, $center] = $this->seedBase();
        $entry = EcBoardEntry::create([
            'evacuation_center_id' => $center->id, 'evacuation_event_id' => $event->id,
            'sex' => 'male', 'age_bracket' => 'adult', 'new_household_head_name' => 'Synced Head',
            'synced_at' => now(), 'remote_id' => 999,
        ]);

        $response = $this->get(route('ec-board-entries.edit', $entry));

        $response->assertRedirect(route('evacuation-centers.ec-board', $center));
    }

    public function test_delete_removes_a_pending_entry(): void
    {
        [$barangay, $event, $center] = $this->seedBase();
        $entry = EcBoardEntry::create([
            'evacuation_center_id' => $center->id, 'evacuation_event_id' => $event->id,
            'sex' => 'male', 'age_bracket' => 'adult', 'new_household_head_name' => 'Removable Head',
        ]);

        $response = $this->delete(route('ec-board-entries.destroy', $entry));

        $response->assertRedirect(route('evacuation-centers.ec-board', $center));
        $this->assertDatabaseMissing('ec_board_entries', ['id' => $entry->id]);
    }

    public function test_basic_info_page_no_longer_shows_ec_board_content_and_links_to_it_prominently(): void
    {
        [$barangay, $event, $center] = $this->seedBase();

        $page = $this->get(route('evacuation-centers.show', $center));

        $page->assertOk();
        $page->assertSee($center->name);
        $page->assertSee('EC Information Board');
        $page->assertSee(route('evacuation-centers.ec-board', $center), false);
        // Content that now lives ONLY on the dedicated EC Board page.
        $page->assertDontSee('As of last sync');
        $page->assertDontSee('Added on this device (pending sync)');
        $page->assertDontSee('Add evacuee (offline)');
    }

    public function test_ec_board_page_links_back_to_the_basic_info_page(): void
    {
        [$barangay, $event, $center] = $this->seedBase();

        $page = $this->get(route('evacuation-centers.ec-board', $center));

        $page->assertOk();
        $page->assertSee('Back to center info');
        $page->assertSee(route('evacuation-centers.show', $center), false);
    }

    public function test_evacuation_centers_list_links_to_the_basic_info_page_by_default(): void
    {
        [$barangay, $event, $center] = $this->seedBase();

        $page = $this->get(route('evacuation-centers.index'));

        $page->assertOk();
        $page->assertSee(route('evacuation-centers.show', $center), false);
        $page->assertDontSee(route('evacuation-centers.ec-board', $center), false);
    }

    public function test_add_evacuee_flow_still_works_end_to_end_on_its_new_dedicated_page(): void
    {
        [$barangay, $event, $center] = $this->seedBase();

        // The form lives on the EC Board page now, not the basic info page.
        $boardPage = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));
        $boardPage->assertOk();
        $boardPage->assertSee('Add evacuee (offline)');

        $store = $this->post(route('ec-board-entries.store', $center), [
            'evacuation_event_id' => $event->id,
            'sex' => 'female',
            'age_bracket' => 'toddler',
            'household_type' => 'new',
            'new_household_head_name' => 'Rosa Santos',
        ]);
        $store->assertRedirect(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));

        $this->assertDatabaseHas('ec_board_entries', [
            'evacuation_center_id' => $center->id,
            'new_household_head_name' => 'Rosa Santos',
            'synced_at' => null,
        ]);

        $afterAdd = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));
        $afterAdd->assertSee('Rosa Santos');
    }

    public function test_sidebar_nav_moves_evacuation_centers_immediately_before_all_evacuees_without_shifting_the_rest(): void
    {
        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official',
            'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);

        $page = $this->get(route('dashboard'));
        $page->assertOk();

        // Matches the nav-link label text itself, not the surrounding tag --
        // the actual Blade output has the icon element and whitespace/
        // indentation between the label and its closing </a>, so anchoring
        // to "</a>" directly (as an earlier version of this test did) never
        // matches at all.
        $content = $page->getContent();
        $dashboardPos = strpos($content, '> Dashboard');
        $familiesPos = strpos($content, '> Registered families');
        $centersPos = strpos($content, '> Evacuation Centers');
        $evacueesPos = strpos($content, '> All Evacuees');

        $this->assertNotFalse($dashboardPos);
        $this->assertNotFalse($familiesPos);
        $this->assertNotFalse($centersPos);
        $this->assertNotFalse($evacueesPos);

        // Dashboard and Registered families keep their existing relative
        // order; Evacuation Centers moves to immediately before All
        // Evacuees (EC Board is now the primary fast-entry workflow).
        $this->assertTrue($dashboardPos < $familiesPos, 'Dashboard must still come before Registered families');
        $this->assertTrue($familiesPos < $centersPos, 'Registered families must still come before Evacuation Centers');
        $this->assertTrue($centersPos < $evacueesPos, 'Evacuation Centers must now come immediately before All Evacuees');
    }
}
