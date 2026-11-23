<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\PendingListAddition;
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

    public function test_adding_a_family_works_offline_and_is_sent_once_with_its_client_ref(): void
    {
        $online = false;
        Http::fake(function ($request) use (&$online) {
            if (! $online) {
                throw new ConnectionException('down');
            }

            return Http::response(['success' => true, 'message' => 'Added to your list.', 'data' => ['id' => 303, 'head' => 'Ana Cruz', 'members_count' => 5, 'status' => 'expected']], 201,
                ['Idempotent-Replayed' => 'true']);
        });

        $this->post(route('families-to-evacuate.store'), [
            'purok_id' => $this->riverside->id, 'head_first_name' => 'Ana', 'head_last_name' => 'Cruz', 'head_sex' => 'female', 'members_count' => 5, 'note' => 'Near the river',
        ])->assertRedirect(route('families-to-evacuate.index', ['purok' => $this->riverside->id]))
            ->assertSessionHas('status', 'Ana Cruz added to the list (Purok 3). It is sent with the next sync.');

        $addition = PendingListAddition::sole();
        $this->assertSame(1, app(LocalData::class)->pendingCount());
        $this->get(route('families-to-evacuate.index'))->assertSeeInOrder(['Purok 3', 'Ana Cruz', 'Added here, waiting to sync'])
            ->assertSeeInOrder(['Families listed', '3', 'Persons listed', '12']);

        $this->postJson(route('sync.step'))->assertJson(['status' => 'stopped', 'stop' => 'offline']);
        $online = true;
        $this->postJson(route('sync.step'))->assertJson(['status' => 'sent']);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/families-to-evacuate') && $r['client_ref'] === $addition->client_ref
            && $r['evacuation_event_id'] === 80 && $r['purok_id'] === 13 && $r['members_count'] === 5 && $r['head_first_name'] === 'Ana' && $r['note'] === 'Near the river');
        $this->assertSame(303, (int) $addition->fresh()->listed_family_remote_id);
    }

    public function test_a_past_family_is_added_by_its_source_id(): void
    {
        Http::fake(['*/families-to-evacuate/past-families*' => Http::response(['data' => [['id' => 90, 'head' => 'Pedro Bautista', 'members_count' => 6]]])]);

        $this->get(route('families-to-evacuate.index', ['search' => 'Pedro']))->assertSee('Pedro Bautista')->assertSee('6 members');
        $this->post(route('families-to-evacuate.store'), ['source_family_id' => 90, 'label' => 'Pedro Bautista', 'members_count' => 6, 'purok_id' => $this->riverside->id])->assertSessionHasNoErrors();

        $payload = PendingListAddition::sole()->toSyncPayload();
        $this->assertSame(90, $payload['source_family_id']);
        $this->assertArrayNotHasKey('head_first_name', $payload);
        $this->assertArrayNotHasKey('members_count', $payload, 'A past family\'s members come from its record.');
    }

    public function test_a_family_added_offline_can_be_checked_in_and_the_check_in_waits_for_it(): void
    {
        $this->event->update(['status' => 'active']);
        $online = false;
        Http::fake(function ($request) use (&$online) {
            if (! $online) {
                throw new ConnectionException('down');
            }

            return str_ends_with($request->url(), '/check-in')
                ? Http::response(['data' => ['registered_family_id' => 77]], 201)
                : Http::response(['data' => ['id' => 304, 'head' => 'Ana Cruz']], 201);
        });

        $this->post(route('families-to-evacuate.store'), ['head_first_name' => 'Ana', 'head_last_name' => 'Cruz', 'members_count' => 2]);
        $local = 'local-'.PendingListAddition::sole()->id;
        $this->post(route('families-to-evacuate.check-in', $local), ['destination' => 'center-'.$this->center->id])->assertSessionHasNoErrors();
        $this->get(route('families-to-evacuate.index'))->assertSee('Checked in at Bacong Gym, waiting to sync');

        $online = true;
        $this->post(route('sync.all'));
        $this->assertSame(0, app(LocalData::class)->pendingCount());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/families-to-evacuate/304/check-in') && $r['evacuation_center_id'] === 7);
        $this->assertSame(304, (int) PendingListCheckIn::sole()->listed_family_remote_id);
    }

    public function test_removing_a_family_added_here_and_a_retried_delete(): void
    {
        $this->post(route('families-to-evacuate.store'), ['head_first_name' => 'Ana', 'head_last_name' => 'Cruz', 'members_count' => 2]);
        $this->delete(route('families-to-evacuate.destroy', 'local-'.PendingListAddition::sole()->id))->assertSessionHas('status', 'Removed from the list.');
        $this->assertSame(0, PendingListAddition::count());
        Http::assertNothingSent();

        // Already removed on the server (a retry): treated as done.
        Http::fake(['*/families-to-evacuate/301' => Http::response(['message' => 'Not found.'], 404), '*/families-to-evacuate?*' => Http::response(['data' => ['families' => []]])]);
        $this->delete(route('families-to-evacuate.destroy', 301))->assertSessionHas('status', 'Removed from the list.');
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
