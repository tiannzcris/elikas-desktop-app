<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientRefTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_try_of_one_entry_carries_the_same_client_ref(): void
    {
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
        $barangay = Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 7, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'active']);
        $this->post(route('ec-board-entries.store', $center), [
            'evacuation_event_id' => $event->id, 'sex' => 'female', 'age_bracket' => 'adult', 'household_type' => 'new',
            'new_household_barangay_id' => $barangay->id, 'new_household_head_name' => 'Reyes',
        ])->assertSessionHasNoErrors();
        $ref = EcBoardEntry::first()->client_ref;
        $this->assertTrue(Str::isUuid($ref));

        // First try: the answer is lost. Second try: the server answers.
        Http::fakeSequence('*/evacuation-centers/7/evacuees')
            ->pushFailedConnection()
            ->push(['data' => ['id' => 50], 'evacuee_id' => 900], 201);

        $this->post(route('sync.all'));
        $this->post(route('sync.all'));

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]['client_ref']);
        $this->assertSame([$ref, $ref], $sent->values()->all());
        $this->assertNotNull(EcBoardEntry::first()->synced_at);
    }
}
