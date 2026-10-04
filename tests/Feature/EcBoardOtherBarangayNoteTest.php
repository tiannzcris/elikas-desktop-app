<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Add evacuee reminds a barangay official working at another barangay's
 * center to add only people staying there -- a note, never a block, the
 * same as the web dashboard's EC Board.
 */
class EcBoardOtherBarangayNoteTest extends TestCase
{
    use RefreshDatabase;

    private const NOTE = 'Add only people who are staying at this center.';

    /** @return array{0: EvacuationEvent, 1: EvacuationCenter, 2: EvacuationCenter} */
    private function seedBase(string $role, ?int $barangayId): array
    {
        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => $role,
            'barangay_id' => $barangayId, 'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        Barangay::create(['remote_id' => 55, 'name' => 'Tupas']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $bacong = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 4, 'name' => 'Bacong Gym', 'status' => 'active']);
        $tupas = EvacuationCenter::create(['remote_id' => 6, 'barangay_remote_id' => 55, 'name' => 'Tupas Barangay Hall', 'status' => 'active']);

        return [$event, $bacong, $tupas];
    }

    public function test_an_official_at_another_barangays_center_sees_the_note_in_add_evacuee(): void
    {
        [$event, , $tupas] = $this->seedBase('barangay_official', 4);

        $page = $this->get(route('evacuation-centers.ec-board', ['center' => $tupas, 'event' => $event->id]));

        $page->assertOk();
        $page->assertSeeInOrder(['id="add-evacuee-modal"', 'This center is in <span class="font-semibold">Tupas</span>. '.self::NOTE, 'Who is this person?'], false);
    }

    public function test_no_note_at_the_officials_own_barangay_or_for_staff_who_see_every_barangay(): void
    {
        [$event, $bacong, $tupas] = $this->seedBase('barangay_official', 4);
        $this->get(route('evacuation-centers.ec-board', ['center' => $bacong, 'event' => $event->id]))->assertDontSee(self::NOTE);

        LocalAuth::query()->update(['role' => 'cswd_personnel', 'barangay_id' => null]);
        $this->get(route('evacuation-centers.ec-board', ['center' => $tupas, 'event' => $event->id]))->assertDontSee(self::NOTE);
    }
}
