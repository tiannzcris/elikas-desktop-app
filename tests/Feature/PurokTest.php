<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\Family;
use App\Models\LocalAuth;
use App\Models\Purok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PurokTest extends TestCase
{
    use RefreshDatabase;

    private function seedBase(): array
    {
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
        $own = Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $other = Barangay::create(['remote_id' => 5, 'name' => 'Other']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 7, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'active']);

        return [$own, $other, $event, $center];
    }

    public function test_refresh_caches_the_own_barangays_puroks(): void
    {
        $this->seedBase();
        Purok::create(['remote_id' => 99, 'barangay_remote_id' => 4, 'name' => 'Gone']);
        Http::fake([
            '*/auth/me' => Http::response(['data' => []]),
            '*/barangays' => Http::response(['data' => [['id' => 4, 'name' => 'Bacong']]]),
            '*/evacuation-events' => Http::response(['data' => []]),
            '*/evacuation-centers?detail=1' => Http::response(['data' => []]),
            '*/puroks*' => Http::response(['data' => [['id' => 12, 'barangay_id' => 4, 'name' => 'Purok 3', 'families_count' => 0]]]),
        ]);

        $this->post(route('reference-data.refresh'));

        $this->assertSame(['Purok 3'], Purok::pluck('name')->all());
        Http::assertSent(fn ($r) => str_contains($r->url(), '/puroks') && $r['barangay_id'] == 4);
    }

    public function test_a_new_household_keeps_its_purok_and_sends_it(): void
    {
        [$own, , $event, $center] = $this->seedBase();
        $purok = Purok::create(['remote_id' => 12, 'barangay_remote_id' => 4, 'name' => 'Purok 3']);

        $this->post(route('ec-board-entries.store', $center), [
            'evacuation_event_id' => $event->id, 'sex' => 'female', 'age_bracket' => 'adult', 'household_type' => 'new',
            'new_household_barangay_id' => $own->id, 'new_household_purok_id' => $purok->id, 'new_household_head_name' => 'Reyes',
        ])->assertSessionHasNoErrors();

        $this->assertSame(12, (int) Family::first()->purok_remote_id);
        $this->assertSame(12, EcBoardEntry::first()->toSyncPayload()['purok_id']);
    }

    public function test_a_purok_of_another_barangay_is_refused(): void
    {
        [, $other, $event, $center] = $this->seedBase();
        $purok = Purok::create(['remote_id' => 12, 'barangay_remote_id' => 4, 'name' => 'Purok 3']);

        $this->post(route('ec-board-entries.store', $center), [
            'evacuation_event_id' => $event->id, 'sex' => 'female', 'age_bracket' => 'adult', 'household_type' => 'new',
            'new_household_barangay_id' => $other->id, 'new_household_purok_id' => $purok->id, 'new_household_head_name' => 'Reyes',
        ])->assertSessionHasErrors('new_household_purok_id');
    }
}
