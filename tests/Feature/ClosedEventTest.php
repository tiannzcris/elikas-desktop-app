<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\Family;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A closed event takes no new evacuees -- on the central server, and on
 * this device as soon as it knows the event is closed -- with the server's
 * own message. Entries already waiting for it stay reachable to be removed,
 * and the server's refusal reads once in their sync error.
 */
class ClosedEventTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'This event is closed. Evacuees can no longer be added to it.';

    /** @return array{0: EvacuationEvent, 1: EvacuationEvent, 2: EvacuationCenter, 3: Barangay} */
    private function seedBase(): array
    {
        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official',
            'barangay_id' => 4, 'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);
        $bacong = Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $open = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Tropical Storm Amang', 'event_type' => 'typhoon', 'status' => 'active']);
        $closed = EvacuationEvent::create(['remote_id' => 2, 'name' => 'Typhoon Kristine', 'event_type' => 'typhoon', 'status' => 'closed']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 4, 'name' => 'Bacong Gym', 'status' => 'active']);

        return [$open, $closed, $center, $bacong];
    }

    private function entry(EvacuationEvent $event, EvacuationCenter $center, Barangay $barangay)
    {
        return $this->post(route('ec-board-entries.store', $center), [
            'evacuation_event_id' => $event->id, 'sex' => 'female', 'age_bracket' => 'adult',
            'household_type' => 'new', 'new_household_barangay_id' => $barangay->id,
            'new_household_head_name' => 'Rosa Bautista', 'head_is_self' => '1',
        ]);
    }

    public function test_add_evacuee_refuses_a_closed_event_with_the_servers_message(): void
    {
        [$open, $closed, $center, $bacong] = $this->seedBase();

        $this->entry($closed, $center, $bacong)->assertSessionHasErrors(['evacuation_event_id' => self::MESSAGE]);
        $this->assertSame(0, EcBoardEntry::count());
        $this->assertSame(0, Family::count());

        $this->entry($open, $center, $bacong)->assertSessionHasNoErrors();
        $this->assertSame(1, EcBoardEntry::count());
    }

    public function test_register_family_refuses_a_closed_event_with_the_servers_message(): void
    {
        [$open, $closed, $center, $bacong] = $this->seedBase();
        $family = fn (EvacuationEvent $event) => [
            'evacuation_event_id' => $event->id, 'barangay_id' => $bacong->id, 'displacement_type' => 'inside_center',
            'evacuation_center_id' => $center->id,
            'members' => [['first_name' => 'Ana', 'last_name' => 'Reyes', 'sex' => 'female', 'date_of_birth' => '1990-01-01', 'is_head_of_family' => '1']],
        ];

        $this->post(route('families.store'), $family($closed))->assertSessionHasErrors(['evacuation_event_id' => self::MESSAGE]);
        $this->assertSame(0, Family::count());

        $this->post(route('families.store'), $family($open))->assertSessionHasNoErrors();
        $this->assertSame(1, Family::count());
    }

    public function test_a_closed_event_is_on_the_board_only_while_entries_for_it_wait_here_and_takes_no_new_ones(): void
    {
        [$open, $closed, $center, $bacong] = $this->seedBase();

        $board = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $closed->id]));
        $board->assertDontSee('Typhoon Kristine');
        $board->assertSee('<option value="'.$open->id.'" selected>', false);

        // An entry saved while this device still thought the event open.
        $closed->update(['status' => 'active']);
        $this->entry($closed, $center, $bacong)->assertSessionHasNoErrors();
        $closed->update(['status' => 'closed']);

        $board = $this->get(route('evacuation-centers.ec-board', ['center' => $center]));
        $board->assertSee('<option value="'.$open->id.'" selected>', false);
        $board->assertSee('Typhoon Kristine (closed)');

        $board = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $closed->id]));
        $board->assertSee('This event is closed. Evacuees can no longer be added to it, so the entries below that are still waiting on this device can\'t sync.', false);
        $board->assertSee('Rosa Bautista');
        $board->assertSee(route('ec-board-entries.destroy', EcBoardEntry::sole()), false);
        $board->assertDontSee('data-open-board-modal="add-evacuee-modal" aria-haspopup', false);
        $board->assertDontSee('id="add-evacuee-modal"', false);

        $this->delete(route('ec-board-entries.destroy', EcBoardEntry::sole()));
        $this->get(route('evacuation-centers.ec-board', ['center' => $center]))->assertDontSee('Typhoon Kristine');
    }

    public function test_the_servers_refusal_reads_once_in_the_sync_error(): void
    {
        [$open, , $center, $bacong] = $this->seedBase();
        $this->entry($open, $center, $bacong);

        Http::fake(['*/evacuation-centers/1/evacuees' => Http::response([
            'success' => false, 'message' => self::MESSAGE, 'errors' => ['evacuation_event_id' => [self::MESSAGE]],
        ], 422)]);
        $this->post(route('families.sync'));

        $this->assertSame(self::MESSAGE, EcBoardEntry::sole()->sync_error);
    }

    public function test_field_errors_the_message_doesnt_say_are_still_listed(): void
    {
        [$open, , $center, $bacong] = $this->seedBase();
        $this->entry($open, $center, $bacong);

        Http::fake(['*/evacuation-centers/1/evacuees' => Http::response([
            'message' => 'The given data was invalid.', 'errors' => ['sex' => ['The sex field is required.'], 'age_bracket' => ['The age bracket field is required.']],
        ], 422)]);
        $this->post(route('families.sync'));

        $this->assertSame('The given data was invalid. The sex field is required. The age bracket field is required.', EcBoardEntry::sole()->sync_error);
    }
}
