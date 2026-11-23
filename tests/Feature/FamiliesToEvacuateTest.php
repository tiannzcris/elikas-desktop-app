<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\PendingListCheckIn;
use App\Models\Purok;
use App\Models\ServerSnapshot;
use App\Services\LocalData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 2.1.0 part 3: families to evacuate per purok, high-risk puroks, check-in offline with client_ref. */
class FamiliesToEvacuateTest extends TestCase
{
    use RefreshDatabase;

    private EvacuationEvent $event;

    private EvacuationCenter $center;

    private Purok $riverside;

    protected function setUp(): void
    {
        parent::setUp();
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'permissions' => ['operations.access', 'puroks.view', 'evacuees.manage', 'families_to_evacuate.manage'],
            'barangay_id' => 4, 'barangay_name' => 'Bacong', 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $this->event = EvacuationEvent::create(['remote_id' => 80, 'name' => 'Incoming Typhoon', 'event_type' => 'typhoon', 'status' => 'monitoring']);
        $this->center = EvacuationCenter::create(['remote_id' => 7, 'barangay_remote_id' => 4, 'name' => 'Bacong Gym', 'status' => 'active', 'capacity_persons' => 100, 'capacity_source' => 'official', 'current_occupancy' => 10]);
        Purok::create(['remote_id' => 11, 'barangay_remote_id' => 4, 'name' => 'Purok 1']);
        $this->riverside = Purok::create(['remote_id' => 13, 'barangay_remote_id' => 4, 'name' => 'Purok 3', 'high_risk_flood' => true]);
        ServerSnapshot::keep('families_to_evacuate.80', ['summary' => ['families' => 2, 'persons' => 7, 'checked_in' => 0], 'families' => [
            ['id' => 301, 'head' => 'Jose Reyes', 'members_count' => 3, 'purok' => ['id' => 11, 'name' => 'Purok 1', 'is_high_risk' => false], 'status' => 'expected', 'from_past_event' => false],
            ['id' => 302, 'head' => 'Maria Santos', 'members_count' => 4, 'purok' => ['id' => 13, 'name' => 'Purok 3', 'is_high_risk' => true], 'status' => 'expected', 'from_past_event' => true],
        ]]);
    }

    public function test_the_list_shows_high_risk_puroks_first_and_says_check_in_waits(): void
    {
        $this->get(route('families-to-evacuate.index'))->assertOk()
            ->assertSeeInOrder(['Purok 3', 'High risk: Flood', 'Maria Santos', 'Purok 1', 'Jose Reyes'])
            ->assertSee('data-check-in-lock', false)
            ->assertSee('Checking in listed families opens when CSWD starts the evacuation.')
            ->assertSee('0 <span class="text-base font-normal text-gray-600">of 2', false)
            ->assertDontSee('gap-2" data-check-in-form', false);
    }

    public function test_adding_a_family_goes_to_the_server_and_keeps_the_purok_for_the_next(): void
    {
        Http::fake([
            '*/families-to-evacuate' => Http::response(['data' => ['id' => 303, 'head' => 'Ana Cruz']], 201),
            '*/families-to-evacuate?*' => Http::response(['data' => ['families' => []]]),
        ]);

        $this->post(route('families-to-evacuate.store'), [
            'purok_id' => $this->riverside->id, 'head_first_name' => 'Ana', 'head_last_name' => 'Cruz', 'head_sex' => 'female', 'members_count' => 5,
        ])->assertRedirect(route('families-to-evacuate.index', ['purok' => $this->riverside->id]))
            ->assertSessionHas('status', 'Ana Cruz added to the list (Purok 3).');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/families-to-evacuate')
            && $r['evacuation_event_id'] === 80 && $r['purok_id'] === 13 && $r['members_count'] === 5 && $r['head_first_name'] === 'Ana');
    }

    public function test_a_past_family_is_added_by_its_source_id(): void
    {
        Http::fake([
            '*/families-to-evacuate/past-families*' => Http::response(['data' => [['id' => 90, 'head' => 'Pedro Bautista', 'members_count' => 6]]]),
            '*/families-to-evacuate' => Http::response(['data' => ['id' => 304, 'head' => 'Pedro Bautista']], 201),
            '*/families-to-evacuate?*' => Http::response(['data' => ['families' => []]]),
        ]);

        $this->get(route('families-to-evacuate.index', ['search' => 'Pedro']))->assertSee('Pedro Bautista')->assertSee('6 members');
        $this->post(route('families-to-evacuate.store'), ['source_family_id' => 90, 'purok_id' => $this->riverside->id])->assertSessionHasNoErrors();
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/families-to-evacuate') && $r['source_family_id'] === 90 && ! isset($r['head_first_name']));
    }

    public function test_adding_offline_says_it_needs_a_connection(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));
        $this->post(route('families-to-evacuate.store'), ['head_first_name' => 'Ana', 'head_last_name' => 'Cruz', 'members_count' => 2])
            ->assertSessionHasErrors('list');
    }

    public function test_marking_a_purok_high_risk(): void
    {
        $purok = Purok::firstWhere('remote_id', 11);
        Http::fake(['*/puroks/11' => Http::response(['data' => ['id' => 11, 'high_risk_flood' => false, 'high_risk_landslide' => true]])]);

        $this->post(route('puroks.mark', $purok), ['high_risk_landslide' => '1'])->assertSessionHas('status', 'Purok 1 marked high risk (Landslide).');
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r['high_risk_landslide'] === true && $r['high_risk_flood'] === false);
        $this->assertTrue($purok->fresh()->high_risk_landslide);
    }

    public function test_check_in_works_offline_and_is_sent_once_with_its_client_ref(): void
    {
        $this->event->update(['status' => 'active']);
        $online = false;
        Http::fake(function ($request) use (&$online) {
            if (! $online) {
                throw new ConnectionException('down');
            }

            return Http::response(['data' => ['registered_family_id' => 5555]], 201);
        });

        $this->get(route('families-to-evacuate.index'))->assertSee('gap-2" data-check-in-form', false);
        $this->post(route('families-to-evacuate.check-in', 302), ['destination' => 'center-'.$this->center->id])
            ->assertSessionHas('status', 'Maria Santos checked in at Bacong Gym. It is sent with the next sync.');

        $checkIn = PendingListCheckIn::sole();
        $this->assertSame(1, app(LocalData::class)->pendingCount());
        $this->assertSame(14, $this->center->fresh()->occupancyHere());
        $this->get(route('families-to-evacuate.index'))->assertSee('Checked in at Bacong Gym, waiting to sync')->assertSee('1 <span class="text-base font-normal text-gray-600">of 2', false);
        // Twice is refused on the device.
        $this->post(route('families-to-evacuate.check-in', 302), ['destination' => 'center-'.$this->center->id])->assertSessionHasErrors('destination');

        // Offline: the run stops, the check-in keeps its place.
        $this->postJson(route('sync.step'))->assertJson(['status' => 'stopped', 'stop' => 'offline']);

        $online = true;
        $this->postJson(route('sync.step'))->assertJson(['status' => 'sent']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/families-to-evacuate/302/check-in') && $r['client_ref'] === $checkIn->client_ref && $r['evacuation_center_id'] === 7);
        $this->assertSame(5555, (int) $checkIn->fresh()->registered_family_id);
        $this->get(route('families-to-evacuate.index'))->assertDontSee('waiting to sync')->assertSee('Checked in');
    }

    public function test_check_in_to_a_private_house_needs_the_host(): void
    {
        $this->event->update(['status' => 'active']);

        $this->post(route('families-to-evacuate.check-in', 301), ['destination' => 'private'])->assertSessionHasErrors('host_name');
        $this->post(route('families-to-evacuate.check-in', 301), ['destination' => 'private', 'host_name' => 'Carmen Reyes'])->assertSessionHasErrors('host_address');
        $this->post(route('families-to-evacuate.check-in', 301), ['destination' => 'private', 'host_name' => 'Carmen Reyes', 'host_purok_id' => $this->riverside->id])->assertSessionHasNoErrors();

        $this->assertSame(['host_name' => 'Carmen Reyes', 'host_purok_id' => 13, 'client_ref' => PendingListCheckIn::sole()->client_ref], PendingListCheckIn::sole()->toSyncPayload());
    }

    public function test_check_in_waits_while_the_event_is_monitored(): void
    {
        $this->post(route('families-to-evacuate.check-in', 302), ['destination' => 'center-'.$this->center->id])
            ->assertSessionHasErrors(['evacuation_event_id' => '"Incoming Typhoon" is being monitored. Checking in listed families opens when CSWD starts the evacuation.']);
        $this->assertSame(0, PendingListCheckIn::count());
    }
}
