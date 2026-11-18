<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\CenterFamilySnapshot;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\Family;
use App\Models\LocalAuth;
use App\Models\ServerSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TransfersTest extends TestCase
{
    use RefreshDatabase;

    private EvacuationEvent $event;

    private EvacuationCenter $hall;

    private EvacuationCenter $school;

    private EvacuationCenter $elsewhere;

    protected function setUp(): void
    {
        parent::setUp();
        Barangay::create(['remote_id' => 13, 'name' => 'Binatagan']);
        Barangay::create(['remote_id' => 49, 'name' => 'Tomolin']);
        $this->event = EvacuationEvent::create(['remote_id' => 85, 'name' => 'Typhoon Drill', 'event_type' => 'typhoon', 'status' => 'active']);
        $this->hall = EvacuationCenter::create(['remote_id' => 71, 'barangay_remote_id' => 13, 'name' => 'LWCES (B)', 'status' => 'active', 'capacity_persons' => 20, 'current_occupancy' => 20]);
        $this->school = EvacuationCenter::create(['remote_id' => 72, 'barangay_remote_id' => 13, 'name' => 'Binatagan School', 'status' => 'on_standby', 'capacity_persons' => 100]);
        $this->elsewhere = EvacuationCenter::create(['remote_id' => 141, 'barangay_remote_id' => 49, 'name' => 'DA Building', 'status' => 'active']);
        CenterFamilySnapshot::create(['evacuation_center_id' => $this->hall->id, 'evacuation_event_id' => $this->event->id, 'fetched_at' => now(),
            'families' => [['id' => 5669, 'name' => 'Bituin', 'here_count' => 4], ['id' => 5672, 'name' => 'Reyes', 'here_count' => 3]]]);
    }

    private function official(): void
    {
        LocalAuth::create(['remote_user_id' => 4, 'name' => 'Binatagan Official', 'email' => 'o@x', 'role' => 'barangay_official',
            'permissions' => ['operations.access', 'evacuees.manage', 'evacuees.transfer', 'transfers.request', 'transfers.receive'],
            'barangay_id' => 13, 'barangay_name' => 'Binatagan', 'api_token' => 't', 'logged_in_at' => now()]);
    }

    private function cswd(): void
    {
        LocalAuth::create(['remote_user_id' => 10, 'name' => 'City', 'email' => 'c@x', 'role' => 'cswd_personnel',
            'permissions' => ['operations.access', 'scope.all_barangays', 'evacuees.transfer', 'transfers.manage', 'page.monitoring'],
            'api_token' => 't', 'logged_in_at' => now()]);
    }

    private function row(array $extra = []): array
    {
        return array_merge(['id' => 4, 'status' => 'pending', 'direction' => 'incoming',
            'from_center' => ['id' => 141, 'name' => 'DA Building'], 'to_center' => ['id' => 71, 'name' => 'LWCES (B)'],
            'from_barangay' => ['id' => 49, 'name' => 'Tomolin'], 'to_barangay' => ['id' => 13, 'name' => 'Binatagan'],
            'families' => 1, 'persons' => 4, 'family_list' => [['head' => 'Bea Bituin', 'members' => 4]],
            'note' => 'Our hall is full.', 'requested_by' => ['id' => 9, 'name' => 'Tomolin Official'], 'requested_at' => '2026-11-18T06:02:11+00:00',
            'decided_by' => null, 'decided_at' => null, 'decided_as' => null, 'decline_reason' => null,
            'escalated_at' => null, 'waiting_minutes' => 3, 'may_decide' => true], $extra);
    }

    public function test_the_list_is_kept_and_only_heads_and_member_counts_are_shown(): void
    {
        $this->official();
        ServerSnapshot::keep('transfers.'.$this->event->id, ['counts' => ['pending' => 1, 'incoming_pending' => 1], 'requests' => [$this->row()]]);

        $this->get(route('transfers.index'))->assertOk()
            ->assertSee('1 family (4 people)')->assertSee('Bea Bituin · 4 members')->assertSee('To your barangay')
            ->assertSee('Our hall is full.')->assertSee('Tomolin Official')->assertSee('As of')
            ->assertSee('data-decide-actions', false)->assertSee('Reason for declining');
        $this->get(route('dashboard'))->assertSee('data-nav-transfers', false);
    }

    public function test_accepting_and_declining_need_a_connection_and_decline_needs_a_reason(): void
    {
        $this->official();
        $this->post(route('transfers.decide', 4), ['decision' => 'decline', 'reason' => ''])->assertSessionHasErrors(['reason' => 'Say why the request is declined.']);

        Http::fake([
            '*/transfer-requests/4/accept' => Http::response(['message' => 'Accepted: the families are now at LWCES (B).', 'data' => $this->row(['status' => 'accepted'])]),
            '*/transfer-requests?*' => Http::response(['data' => ['counts' => ['pending' => 0], 'requests' => [$this->row(['status' => 'accepted', 'may_decide' => false])]]]),
        ]);
        $this->post(route('transfers.decide', 4), ['decision' => 'accept'])
            ->assertRedirect(route('transfers.index'))->assertSessionHas('status', 'Accepted: the families are now at LWCES (B).');
        $this->get(route('transfers.index'))->assertSee('Accepted')->assertDontSee('>Accept</button>', false);
    }

    public function test_a_decision_offline_sends_nothing(): void
    {
        $this->official();
        Http::fake(fn () => throw new ConnectionException('down'));
        $this->from(route('transfers.index'))->post(route('transfers.decide', 4), ['decision' => 'decline', 'reason' => 'Full too'])
            ->assertSessionHasErrors(['decide' => 'Needs a connection. Nothing was sent.']);
    }

    public function test_the_board_offers_ticks_only_for_families_the_server_knows(): void
    {
        $this->official();
        Family::create(['barangay_id' => Barangay::first()->id, 'evacuation_event_id' => $this->event->id, 'evacuation_center_id' => $this->hall->id,
            'displacement_type' => 'inside_center', 'created_via_ec_board' => true, 'name' => 'Waiting Family']);

        $page = $this->get(route('evacuation-centers.ec-board', $this->hall))->assertOk();
        $page->assertSee('Move ticked families');
        $this->assertSame(2, substr_count($page->getContent(), 'data-move-tick>'));
        $page->assertSee('can be moved once it syncs');
    }

    public function test_an_official_asks_another_barangay_with_live_suggestions(): void
    {
        $this->official();
        Http::fake([
            '*/transfer-requests/suggestions*' => Http::response(['data' => ['centers' => [
                ['evacuation_center' => ['id' => 141, 'name' => 'DA Building'], 'barangay' => ['id' => 49, 'name' => 'Tomolin'], 'distance_km' => 2.4,
                    'distance_basis' => 'center', 'capacity_persons' => 120, 'capacity_source' => 'estimated', 'remaining_persons' => 56, 'fits' => true, 'capacity_unknown' => false],
                ['evacuation_center' => ['id' => 150, 'name' => 'Tomolin Chapel'], 'barangay' => ['id' => 49, 'name' => 'Tomolin'], 'distance_km' => 2.9,
                    'distance_basis' => 'barangay_centroid', 'capacity_persons' => null, 'remaining_persons' => null, 'fits' => false, 'capacity_unknown' => true],
            ]]]),
            '*/transfer-requests' => Http::response(['data' => $this->row(['direction' => 'outgoing', 'to_center' => ['id' => 141, 'name' => 'DA Building']])], 201),
            '*/transfer-requests?*' => Http::response(['data' => ['requests' => []]]),
        ]);

        $this->get(route('transfers.move', ['center' => $this->hall, 'families' => ['remote-5669', 'remote-5672']]))->assertOk()
            ->assertSee('Move 2 families (7 people)')->assertSee('DA Building')->assertSee('Room for 56')
            ->assertSee('about 2.9 km')->assertSee('Capacity unknown')->assertSee('Binatagan School');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'suggestions') && $r['from_center_id'] == 71 && $r['persons'] == 7);

        $this->post(route('transfers.request', $this->hall), ['families' => ['remote-5669', 'remote-5672'], 'to_center_remote_id' => 141, 'note' => 'Full'])
            ->assertRedirect(route('transfers.index'));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/transfer-requests')
            && $r['family_ids'] === [5669, 5672] && $r['to_center_id'] === 141 && $r['evacuation_event_id'] === 85 && $r['note'] === 'Full');
    }

    public function test_offline_the_move_page_says_it_needs_a_connection(): void
    {
        $this->official();
        Http::fake(fn () => throw new ConnectionException('down'));
        $this->get(route('transfers.move', ['center' => $this->hall, 'families' => ['remote-5669']]))
            ->assertSee('Needs a connection to find centers with room.');
    }

    public function test_an_official_moves_directly_only_to_their_own_centers(): void
    {
        $this->official();
        Http::fake([
            '*/families/5669/evacuation-center' => Http::response(['data' => ['id' => 5669]]),
            '*/evacuation-centers/*/families*' => Http::response(['data' => []]),
        ]);

        $this->post(route('transfers.move-direct', $this->hall), ['families' => ['remote-5669'], 'to_center_id' => $this->elsewhere->id])->assertForbidden();
        $this->post(route('transfers.move-direct', $this->hall), ['families' => ['remote-5669'], 'to_center_id' => $this->school->id, 'reason' => 'Full'])
            ->assertRedirect(route('evacuation-centers.ec-board', $this->hall))->assertSessionHas('status', '1 family moved to Binatagan School.');
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r['evacuation_center_id'] === 72 && $r['reason'] === 'Full');
    }

    public function test_cswd_moves_any_family_with_a_reason(): void
    {
        $this->cswd();
        Http::fake([
            '*/evacuation-centers/71/families*' => Http::response(['data' => [['id' => 5669, 'name' => 'Bituin', 'here_count' => 4]]]),
            '*/families/5669/evacuation-center' => Http::response(['data' => ['id' => 5669]]),
        ]);

        $this->get(route('transfers.city-move', ['from' => $this->hall->id]))->assertOk()->assertSee('Bituin · 4 here')->assertSee('DA Building');
        $this->post(route('transfers.city-move.store'), ['from_center_id' => $this->hall->id, 'family_remote_id' => 5669, 'to_center_id' => $this->elsewhere->id, 'reason' => ''])
            ->assertSessionHasErrors(['reason' => 'Say why the family is being transferred.']);
        $this->post(route('transfers.city-move.store'), ['from_center_id' => $this->hall->id, 'family_remote_id' => 5669, 'family_label' => 'Bituin',
            'to_center_id' => $this->elsewhere->id, 'reason' => 'Roof damaged'])->assertSessionHas('status', 'Bituin moved to DA Building.');
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r['evacuation_center_id'] === 141 && $r['reason'] === 'Roof damaged');
    }

    public function test_a_transfer_notification_opens_the_transfers_screen_from_its_href(): void
    {
        $this->official();
        Http::fake([
            '*/notifications' => Http::response(['data' => ['unread' => 1, 'notifications' => [
                ['id' => 'a', 'title' => 'Transfer request: 1 family (4 persons)', 'message' => 'Tomolin asks…', 'href' => '/transfer-requests', 'read' => false, 'created_at' => '2026-11-18T06:02:11+00:00'],
            ]]]),
            '*/transfer-requests?*' => Http::response(['data' => ['counts' => ['incoming_pending' => 1], 'requests' => [$this->row()]]]),
        ]);

        $feed = $this->getJson(route('notifications.feed'))->assertOk()->assertJsonPath('unread', 1)->json('notifications.0');
        $this->assertTrue($feed['in_app']);
        $this->get($feed['open'])->assertRedirect(route('transfers.index'));
        $this->assertSame(4, ServerSnapshot::named('transfers.'.$this->event->id)->payload['requests'][0]['id']);
    }
}
