<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\PendingPrivateHouseFamily;
use App\Models\Purok;
use App\Models\ServerSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PrivateHousesTest extends TestCase
{
    use RefreshDatabase;

    private EvacuationEvent $event;

    protected function setUp(): void
    {
        parent::setUp();
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'barangay_name' => 'Bacong', 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $this->event = EvacuationEvent::create(['remote_id' => 79, 'name' => 'Typhoon', 'event_type' => 'typhoon', 'status' => 'active']);
        EvacuationCenter::create(['remote_id' => 7, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'active', 'capacity_persons' => 100]);
    }

    private function add(array $extra = [])
    {
        return $this->post(route('private-houses.store'), array_merge([
            'head' => ['first_name' => 'Rosa', 'last_name' => 'Santos', 'sex' => 'female', 'age' => 44],
            'members' => [['first_name' => 'Lito', 'last_name' => 'Santos', 'sex' => 'male', 'age' => 9], ['first_name' => '', 'last_name' => '', 'sex' => '', 'age' => '']],
            'host_name' => 'Carmen Reyes', 'host_address' => 'Zone 3', 'is_4ps_beneficiary' => '1',
        ], $extra));
    }

    public function test_a_family_is_added_offline_and_sent_with_its_client_ref(): void
    {
        $purok = Purok::create(['remote_id' => 12, 'barangay_remote_id' => 4, 'name' => 'Purok 2']);
        $this->add(['host_purok_id' => $purok->id])->assertSessionHasNoErrors()->assertRedirect(route('private-houses.index'));

        $family = PendingPrivateHouseFamily::first();
        $this->assertSame(2, $family->personCount(), 'the blank member row is dropped');
        $this->get(route('private-houses.index'))->assertSee('Rosa Santos')->assertSee('Carmen Reyes')->assertSee('Waiting to sync');

        Http::fake(['*/barangays/4/private-house-families' => Http::response(['data' => ['id' => 3601, 'label' => 'Rosa Santos', 'member_count' => 2]], 201)]);
        $this->postJson(route('sync.step'))->assertJson(['status' => 'sent']);

        Http::assertSent(fn ($r) => $r['evacuation_event_id'] === 79 && $r['host_name'] === 'Carmen Reyes' && $r['host_purok_id'] === 12
            && $r['head'] === ['first_name' => 'Rosa', 'last_name' => 'Santos', 'sex' => 'female', 'age' => 44]
            && count($r['members']) === 1 && $r['is_4ps_beneficiary'] === true && $r['client_ref'] === $family->client_ref);
        $this->assertSame(3601, (int) $family->fresh()->remote_family_id);
    }

    public function test_the_host_and_an_address_or_purok_are_required(): void
    {
        $this->add(['host_name' => '', 'host_address' => ''])->assertSessionHasErrors(['host_name', 'host_address']);
        $this->assertSame(0, PendingPrivateHouseFamily::count());
    }

    public function test_the_ec_board_counts_private_houses_and_monitoring_shows_them(): void
    {
        ServerSnapshot::keep('private_houses.'.$this->event->id, ['barangays' => [['barangay' => ['id' => 4], 'families' => 2, 'persons' => 7]], 'families' => []]);
        $this->add();

        $this->get(route('ec-board.index'))->assertOk()
            ->assertSee('data-private-houses-card', false)
            ->assertSeeInOrder(['In private houses', '9', '3 families']);
        $this->get(route('evacuation-centers.ec-board', 1))->assertSee('Add to Private houses');

        LocalAuth::query()->delete();
        LocalAuth::create(['remote_user_id' => 2, 'name' => 'C', 'email' => 'c@x', 'role' => 'cswd_personnel',
            'permissions' => ['operations.access', 'scope.all_barangays', 'page.monitoring'], 'api_token' => 't', 'logged_in_at' => now()]);
        ServerSnapshot::keep('monitoring.overview', ['evacuation_event' => ['id' => 79, 'name' => 'Typhoon'], 'generated_at' => now()->toIso8601String(),
            'data' => ['figures' => ['persons' => 1958, 'families' => 551, 'inside' => 1790, 'inside_families' => 508,
                'private_houses' => ['persons' => 168, 'families' => 43]], 'attention' => []]]);
        $this->get(route('monitoring.index'))->assertSeeInOrder(['In private houses', '168', '43 families'])->assertDontSee('0 outside');
    }
}
