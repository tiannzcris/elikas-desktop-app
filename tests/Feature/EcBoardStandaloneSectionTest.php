<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\EvacuationCenterQuickCount;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers the REAL standalone EC Board section (barangay -> centers ->
 * board, replacing the earlier stopgap that just relabeled the old
 * Evacuation Centers management link) and the sectoral/4Ps aggregate
 * reporting confirmed missing until now -- see
 * EvacuationCenterQuickCount's own docblock for why this stays a
 * manually-reported figure, never derived from individual evacuee
 * entries the way age/sex is.
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

    public function test_ec_board_page_shows_sectoral_group_form_with_all_eight_categories(): void
    {
        $this->login();
        Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Center One', 'status' => 'active']);

        $page = $this->get(route('evacuation-centers.ec-board', $center));

        $page->assertOk();
        $page->assertSee('Sectoral group breakdown');
        $page->assertSee('4Ps beneficiary families');
        foreach (EvacuationCenterQuickCount::SECTORAL_GROUPS as $label) {
            $page->assertSee($label);
        }
    }

    /**
     * Confirmed bug (mirrors the same fix already applied on the web
     * dashboard, commit 3603162): this field was sitting inside the
     * Sectoral Group card instead of the top header block, grouped with
     * barangay/center/event. assertSeeInOrder over the raw rendered HTML
     * is the only reliable way to prove DOM *position*, not just presence
     * -- test_ec_board_page_shows_sectoral_group_form_with_all_eight_categories
     * above already covered presence and would pass either way.
     */
    public function test_the_4ps_field_appears_in_the_header_row_not_the_sectoral_card(): void
    {
        $this->login();
        Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Center One', 'status' => 'active']);

        $page = $this->get(route('evacuation-centers.ec-board', $center));

        $page->assertOk();
        $page->assertSeeInOrder(['4Ps beneficiary families', 'Add evacuee', 'Quick departure', 'Sectoral group breakdown']);
        // Still saves through the sectoral form despite living outside it
        // in the DOM -- see the form="" attribute on the relocated input.
        $page->assertSee('form="ecboard-sectoral-form"', false);
    }

    public function test_saving_sectoral_figures_stores_them_locally_as_pending(): void
    {
        $this->login();
        Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Center One', 'status' => 'active']);

        $response = $this->post(route('evacuation-centers.sectoral.update', $center), [
            'evacuation_event_id' => $event->id,
            'beneficiaries_4ps' => 5,
            'sectoral_groups' => [
                ['sectoral_group' => 'pwd', 'male_count' => 2, 'female_count' => 1],
                ['sectoral_group' => 'pregnant_women', 'male_count' => 0, 'female_count' => 3],
            ],
        ]);

        $response->assertRedirect(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));

        $quickCount = EvacuationCenterQuickCount::where('evacuation_center_id', $center->id)
            ->where('evacuation_event_id', $event->id)
            ->first();
        $this->assertNotNull($quickCount);
        $this->assertSame(5, $quickCount->beneficiaries_4ps);
        $this->assertNull($quickCount->synced_at);
        $this->assertDatabaseHas('evacuation_center_quick_count_sectoral_groups', [
            'evacuation_center_quick_count_id' => $quickCount->id, 'sectoral_group' => 'pwd', 'male_count' => 2, 'female_count' => 1,
        ]);

        $page = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));
        $page->assertSee('Saved on this device, not yet synced.');
    }

    public function test_resaving_sectoral_figures_overwrites_the_same_row_instead_of_creating_a_new_one(): void
    {
        $this->login();
        Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Center One', 'status' => 'active']);

        $payload = fn (int $pwdMale) => [
            'evacuation_event_id' => $event->id,
            'beneficiaries_4ps' => 5,
            'sectoral_groups' => [['sectoral_group' => 'pwd', 'male_count' => $pwdMale, 'female_count' => 0]],
        ];

        $this->post(route('evacuation-centers.sectoral.update', $center), $payload(2));
        $this->post(route('evacuation-centers.sectoral.update', $center), $payload(9));

        $this->assertSame(1, EvacuationCenterQuickCount::where('evacuation_center_id', $center->id)->count());
        $quickCount = EvacuationCenterQuickCount::where('evacuation_center_id', $center->id)->firstOrFail();
        $this->assertDatabaseHas('evacuation_center_quick_count_sectoral_groups', [
            'evacuation_center_quick_count_id' => $quickCount->id, 'sectoral_group' => 'pwd', 'male_count' => 9,
        ]);
    }

    public function test_an_invalid_sectoral_group_key_is_rejected(): void
    {
        $this->login();
        Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Center One', 'status' => 'active']);

        $response = $this->post(route('evacuation-centers.sectoral.update', $center), [
            'evacuation_event_id' => $event->id,
            'beneficiaries_4ps' => 0,
            'sectoral_groups' => [['sectoral_group' => 'not_a_real_group', 'male_count' => 1, 'female_count' => 0]],
        ]);

        $response->assertSessionHasErrors('sectoral_groups.0.sectoral_group');
        $this->assertSame(0, EvacuationCenterQuickCount::count());
    }

    public function test_sync_now_pushes_pending_sectoral_figures_to_the_central_server(): void
    {
        $this->login();
        Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Center One', 'status' => 'active']);

        $this->post(route('evacuation-centers.sectoral.update', $center), [
            'evacuation_event_id' => $event->id,
            'beneficiaries_4ps' => 3,
            'sectoral_groups' => [['sectoral_group' => 'solo_parent', 'male_count' => 1, 'female_count' => 2]],
        ]);

        Http::fake(['*/evacuation-centers/1/quick-count' => Http::response(['data' => []], 200)]);

        $response = $this->post(route('families.sync'));

        $response->assertSessionHas('status', '1 record(s) synced successfully.');

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && str_contains($request->url(), '/evacuation-centers/1/quick-count')
                && $request['evacuation_event_id'] === 1
                && $request['beneficiaries_4ps'] === 3
                && collect($request['sectoral_groups'])->firstWhere('sectoral_group', 'solo_parent')['male_count'] === 1;
        });

        $quickCount = EvacuationCenterQuickCount::where('evacuation_center_id', $center->id)->firstOrFail();
        $this->assertNotNull($quickCount->synced_at);
        $this->assertNull($quickCount->sync_error);
    }

    public function test_a_sectoral_sync_failure_is_recorded_and_shown_on_the_board(): void
    {
        $this->login();
        Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Center One', 'status' => 'active']);

        $this->post(route('evacuation-centers.sectoral.update', $center), [
            'evacuation_event_id' => $event->id,
            'beneficiaries_4ps' => 3,
            'sectoral_groups' => [],
        ]);

        Http::fake(['*/evacuation-centers/1/quick-count' => Http::response([
            'message' => 'The given data was invalid.',
            'errors' => ['beneficiaries_4ps' => ['The beneficiaries 4ps field is required.']],
        ], 422)]);

        $this->post(route('families.sync'));

        $quickCount = EvacuationCenterQuickCount::where('evacuation_center_id', $center->id)->firstOrFail();
        $this->assertNull($quickCount->synced_at);
        $this->assertNotNull($quickCount->sync_error);

        $page = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));
        $page->assertSee($quickCount->sync_error);
    }
}
