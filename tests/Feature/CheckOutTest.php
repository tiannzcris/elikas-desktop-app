<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\CenterFamilySnapshot;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\Family;
use App\Models\LocalAuth;
use App\Models\PendingCheckOut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckOutTest extends TestCase
{
    use RefreshDatabase;

    private EvacuationCenter $center;

    private EvacuationEvent $event;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
        $this->barangay = Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $this->event = EvacuationEvent::create(['remote_id' => 24, 'name' => 'Typhoon', 'event_type' => 'typhoon', 'status' => 'active']);
        $this->center = EvacuationCenter::create(['remote_id' => 7, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'active']);
        CenterFamilySnapshot::create(['evacuation_center_id' => $this->center->id, 'evacuation_event_id' => $this->event->id,
            'families' => [['id' => 50, 'name' => 'Reyes', 'here_count' => 3, 'purok' => ['id' => 1, 'name' => 'Purok 3']]], 'fetched_at' => now()]);
    }

    private function checkOut(string $family): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('check-outs.store', $this->center), [
            'evacuation_event_id' => $this->event->id, 'family' => $family, 'status' => 'returned_home',
        ]);
    }

    public function test_a_family_here_is_checked_out_offline_then_sent(): void
    {
        $this->get(route('evacuation-centers.ec-board', ['center' => $this->center, 'event' => $this->event->id]))
            ->assertSee('Families here')->assertSee('Reyes')->assertSee('3 here · Purok 3');

        $this->checkOut('remote-50')->assertSessionHasNoErrors();
        $this->checkOut('remote-50')->assertSessionHasErrors('family');
        $this->get(route('evacuation-centers.ec-board', ['center' => $this->center, 'event' => $this->event->id]))
            ->assertSee('Checked out, waiting to sync');

        Http::fake(['*/families/bulk-check-out' => Http::response(['data' => ['results' => [
            ['family_id' => 50, 'status' => 'done', 'reason_code' => null, 'people_checked_out' => 3],
        ], 'families_done' => 1]])]);
        $this->postJson(route('sync.step'))->assertJson(['status' => 'sent']);

        Http::assertSent(fn ($r) => $r['family_ids'] === [50] && $r['status'] === 'returned_home'
            && $r['evacuation_center_id'] === 7 && $r['evacuation_event_id'] === 24);
        $this->assertSame(3, PendingCheckOut::first()->people_checked_out);
    }

    public function test_already_checked_out_on_the_server_counts_as_done(): void
    {
        $this->checkOut('remote-50');
        Http::fake(['*/families/bulk-check-out' => Http::response(['success' => false, 'message' => 'No family was marked as departed.',
            'errors' => ['results' => [['family_id' => 50, 'status' => 'failed', 'reason_code' => 'none_checked_in', 'people_checked_out' => 0]]]], 422)]);

        $this->postJson(route('sync.step'))->assertJson(['status' => 'sent']);
        $this->assertNotNull(PendingCheckOut::first()->synced_at);
    }

    public function test_a_family_added_here_waits_for_its_own_record_before_its_check_out(): void
    {
        $this->post(route('ec-board-entries.store', $this->center), [
            'evacuation_event_id' => $this->event->id, 'sex' => 'female', 'age_bracket' => 'adult', 'household_type' => 'new',
            'new_household_barangay_id' => $this->barangay->id, 'new_household_head_name' => 'Santos',
        ])->assertSessionHasNoErrors();
        $local = Family::all()->firstWhere('name', 'Santos');
        $this->checkOut('local-'.$local->id)->assertSessionHasNoErrors();

        Http::fake([
            '*/evacuees' => Http::response(['data' => ['id' => 77], 'evacuee_id' => 900], 201),
            '*/families/bulk-check-out' => Http::response(['data' => ['results' => [['family_id' => 77, 'status' => 'done', 'people_checked_out' => 1]]]]),
        ]);
        $this->postJson(route('sync.step'))->assertJson(['status' => 'sent']); // the entry
        $this->postJson(route('sync.step'))->assertJson(['status' => 'sent']); // then the check-out
        Http::assertSent(fn ($r) => str_contains($r->url(), 'bulk-check-out') && $r['family_ids'] === [77]);
        $this->assertNotNull(EcBoardEntry::first()->synced_at);
    }
}
