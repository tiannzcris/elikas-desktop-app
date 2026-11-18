<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\CenterFamilySnapshot;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\PendingCheckOut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClosedEventReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_closed_event_takes_no_edits_and_no_check_outs(): void
    {
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $closed = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon Amang', 'event_type' => 'typhoon', 'status' => 'closed']);
        $center = EvacuationCenter::create(['remote_id' => 7, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'active']);
        $entry = EcBoardEntry::create(['evacuation_center_id' => $center->id, 'evacuation_event_id' => $closed->id, 'sex' => 'male', 'age_bracket' => 'adult']);
        CenterFamilySnapshot::create(['evacuation_center_id' => $center->id, 'evacuation_event_id' => $closed->id,
            'families' => [['id' => 50, 'name' => 'Reyes', 'here_count' => 2]], 'fetched_at' => now()]);

        $this->selectEvent($closed);
        $board = $this->get(route('evacuation-centers.ec-board', ['center' => $center]))->assertOk();
        $board->assertDontSee(route('ec-board-entries.edit', $entry), false)
            ->assertDontSee('>Check out</button>', false)
            ->assertSee('Delete');

        $this->get(route('ec-board-entries.edit', $entry))->assertRedirect()->assertSessionHasErrors('evacuation_event_id');
        $this->post(route('check-outs.store', $center), ['evacuation_event_id' => $closed->id, 'family' => 'remote-50', 'status' => 'returned_home'])
            ->assertSessionHasErrors('evacuation_event_id');
        $this->assertSame(0, PendingCheckOut::count());
    }
}
