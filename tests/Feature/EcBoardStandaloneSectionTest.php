<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers the standalone EC Board section (barangay -> centers -> board)
 * and its sectoral display -- every sectoral figure is counted live by the
 * central server now; nothing is typed in on this device.
 */
class EcBoardStandaloneSectionTest extends TestCase
{
    use RefreshDatabase;

    private function login(): void
    {
        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official',
            'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------
    // Part 1: barangay -> centers -> board navigation
    // -----------------------------------------------------------------

    public function test_ec_board_landing_page_lists_only_barangays_with_at_least_one_open_center(): void
    {
        $this->login();
        Barangay::create(['remote_id' => 1, 'name' => 'Has A Center']);
        Barangay::create(['remote_id' => 2, 'name' => 'Has No Centers']);
        Barangay::create(['remote_id' => 3, 'name' => 'Only A Closed Center']);
        EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Center One', 'status' => 'active']);
        EvacuationCenter::create(['remote_id' => 2, 'barangay_remote_id' => 3, 'name' => 'Closed Center', 'status' => 'closed']);

        $page = $this->get(route('ec-board.index'));

        $page->assertOk();
        $page->assertSee('Has A Center');
        $page->assertDontSee('Has No Centers');
        $page->assertDontSee('Only A Closed Center');
    }

    public function test_clicking_a_barangay_shows_only_that_barangays_centers(): void
    {
        $this->login();
        $a = Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $b = Barangay::create(['remote_id' => 2, 'name' => 'Barangay B']);
        EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Center One', 'status' => 'active']);
        EvacuationCenter::create(['remote_id' => 2, 'barangay_remote_id' => 2, 'name' => 'Center Two', 'status' => 'active']);

        $page = $this->get(route('ec-board.centers', $a));

        $page->assertOk();
        $page->assertSee('Center One');
        $page->assertDontSee('Center Two');
    }

    public function test_a_centers_list_links_straight_to_its_own_ec_board_not_the_basic_info_page_first(): void
    {
        $this->login();
        $barangay = Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Center One', 'status' => 'active']);

        $page = $this->get(route('ec-board.centers', $barangay));

        $page->assertSee(route('evacuation-centers.ec-board', $center), false);
    }

    public function test_sidebar_ec_board_link_points_to_the_new_barangay_landing_page(): void
    {
        $this->login();

        $page = $this->get(route('dashboard'));

        $page->assertSee(route('ec-board.index'), false);
    }

    public function test_old_evacuation_centers_management_pages_remain_fully_reachable(): void
    {
        $this->login();
        $barangay = Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Center One', 'status' => 'active']);

        $landing = $this->get(route('ec-board.index'));
        $landing->assertSee(route('evacuation-centers.index'), false);

        $centers = $this->get(route('ec-board.centers', $barangay));
        $centers->assertSee(route('evacuation-centers.show', $center), false);

        $this->get(route('evacuation-centers.index'))->assertOk();
        $this->get(route('evacuation-centers.show', $center))->assertOk();
    }

    // -----------------------------------------------------------------
    // Part 2: sectoral group reporting
    // -----------------------------------------------------------------

    /**
     * Typed family counts are retired: the central server discards them,
     * so offering the form at all would be a silent data-loss trap.
     */
    public function test_ec_board_page_no_longer_offers_typing_family_counts(): void
    {
        $this->login();
        Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Center One', 'status' => 'active']);

        $page = $this->get(route('evacuation-centers.ec-board', $center));

        $page->assertOk();
        $page->assertSeeInOrder(['Sectoral group', 'Male', 'Female', 'Total', 'On this device']);
        $page->assertDontSee('Edit family counts');
        $page->assertDontSee('family counts');
    }

    /**
     * Confirmed bug (mirrors the same fix already applied on the web
     * dashboard, commit 3603162): this field was sitting inside the
     * Sectoral Group card instead of the top header block, grouped with
     * barangay/center/event. assertSeeInOrder over the raw rendered HTML
     * is the only reliable way to prove DOM *position*, not just presence.
     * The board reads header -> Age & Sex -> Sectoral, then the pending
     * list, then the Add evacuee pop-up.
     */
    public function test_the_board_reads_in_the_official_template_order_with_the_panels_after_it(): void
    {
        $this->login();
        Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Center One', 'status' => 'active']);

        $page = $this->get(route('evacuation-centers.ec-board', $center));

        $page->assertOk();
        // Add evacuee's button sits at the top; the board, then its
        // pending entries, then the form itself as a pop-up.
        $page->assertSeeInOrder(['Add evacuee', '4Ps beneficiary families', 'Age group', 'Sectoral group', 'Pending entries for this event', 'id="add-evacuee-modal"'], false);
    }

    /**
     * Quick departure is gone from the board, as on the web dashboard: it
     * marked people as departed by age group and sex, not by who actually
     * left. Nothing on this device calls the central endpoint any more.
     */
    public function test_quick_departure_is_gone_from_the_board_and_this_app(): void
    {
        $this->login();
        Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Center One', 'status' => 'active']);

        $page = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));

        $page->assertOk();
        $page->assertSee('Add evacuee');
        $page->assertDontSee('Quick departure');
        $page->assertDontSee('quick-departure', false);
        $page->assertDontSee('Mark as departed');
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('evacuation-centers.quick-departure'));

        \Illuminate\Support\Facades\Http::fake();
        $this->post('/evacuation-centers/'.$center->id.'/quick-departure', ['evacuation_event_id' => $event->id])->assertNotFound();
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    /**
     * The typed-family-counts write path is gone entirely, not just hidden:
     * the central server discards typed figures, so any way to save one
     * would be a silent data-loss trap.
     */
    public function test_the_retired_family_counts_endpoints_no_longer_exist(): void
    {
        $this->login();
        Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Center One', 'status' => 'active']);

        $this->assertFalse(Route::has('evacuation-centers.sectoral.update'));
        $this->assertFalse(Route::has('evacuation-centers.sectoral.edit'));
        $this->assertFalse(Route::has('quick-counts.destroy'));

        $this->post("/evacuation-centers/{$center->id}/sectoral", [
            'evacuation_event_id' => $event->id,
            'sectoral_groups' => [['sectoral_group' => 'child_headed_family', 'male_count' => 2, 'female_count' => 1]],
        ])->assertNotFound();
        $this->get("/evacuation-centers/{$center->id}/sectoral/edit?event={$event->id}")->assertNotFound();

        $this->assertFalse(Schema::hasTable('evacuation_center_quick_counts'));
        $this->assertFalse(Schema::hasTable('evacuation_center_quick_count_sectoral_groups'));
    }

    public function test_sync_sends_nothing_for_sectoral_figures(): void
    {
        $this->login();
        Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Center One', 'status' => 'active']);

        Http::fake();

        $response = $this->post(route('families.sync'));

        $response->assertSessionHas('status', 'Nothing to sync -- everything is already up to date.');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/quick-count'));
    }
}
