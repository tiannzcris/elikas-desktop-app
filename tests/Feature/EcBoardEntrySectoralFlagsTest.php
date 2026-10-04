<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Optional per-person sectoral flags on "Add Evacuee": stored as true or
 * null ("not recorded", never false), synced as ticked flags only, and
 * counted in the EC Board's "Sectoral -- pending from Add Evacuee" table.
 */
class EcBoardEntrySectoralFlagsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: EvacuationEvent, 1: EvacuationCenter} */
    private function seedBase(): array
    {
        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official',
            'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);
        Barangay::create(['remote_id' => 1, 'name' => 'Barangay A']);
        $event = EvacuationEvent::create(['remote_id' => 7, 'name' => 'Typhoon A', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 1, 'name' => 'Barangay Hall', 'status' => 'active']);

        return [$event, $center];
    }

    private function addEvacuee(EvacuationEvent $event, EvacuationCenter $center, array $overrides = [])
    {
        return $this->post(route('ec-board-entries.store', $center), array_merge([
            'evacuation_event_id' => $event->id,
            'sex' => 'female',
            'age_bracket' => 'adult',
            'household_type' => 'new', 'new_household_barangay_id' => \App\Models\Barangay::value('id'),
            'new_household_head_name' => 'Maria Santos',
        ], $overrides));
    }

    public function test_ticked_flags_are_stored_true_and_unticked_ones_stay_null(): void
    {
        [$event, $center] = $this->seedBase();

        $this->addEvacuee($event, $center, ['sex' => 'male', 'is_pwd' => '1', 'is_4ps_beneficiary' => '1'])
            ->assertSessionHasNoErrors();

        $entry = EcBoardEntry::firstOrFail();
        $this->assertTrue($entry->is_pwd);
        $this->assertTrue($entry->is_4ps_beneficiary);
        foreach (['is_pregnant', 'is_lactating', 'is_solo_parent', 'is_indigenous_person'] as $flag) {
            $this->assertNull($entry->{$flag}, "{$flag} should be null (not recorded), not false");
        }
    }

    public function test_no_flags_ticked_stores_all_six_as_null(): void
    {
        [$event, $center] = $this->seedBase();

        $this->addEvacuee($event, $center)->assertSessionHasNoErrors();

        $entry = EcBoardEntry::firstOrFail();
        foreach (array_keys(EcBoardEntry::SECTORAL_FLAGS) as $flag) {
            $this->assertNull($entry->{$flag});
        }
    }

    public function test_a_male_evacuee_cannot_be_marked_pregnant_or_lactating(): void
    {
        [$event, $center] = $this->seedBase();

        $this->addEvacuee($event, $center, ['sex' => 'male', 'is_pregnant' => '1'])
            ->assertSessionHasErrors('is_pregnant');
        $this->addEvacuee($event, $center, ['sex' => 'male', 'is_lactating' => '1'])
            ->assertSessionHasErrors('is_lactating');

        $this->assertSame(0, EcBoardEntry::count());
    }

    public function test_unticking_a_flag_while_editing_a_pending_entry_clears_it_back_to_null(): void
    {
        [$event, $center] = $this->seedBase();
        $this->addEvacuee($event, $center, ['is_pwd' => '1', 'is_pregnant' => '1']);
        $entry = EcBoardEntry::firstOrFail();

        $this->put(route('ec-board-entries.update', $entry), [
            'evacuation_event_id' => $event->id,
            'sex' => 'female',
            'age_bracket' => 'adult',
            'household_type' => 'existing',
            'household_family_local_id' => (string) $entry->household_family_local_id,
            'is_pregnant' => '1', // kept; is_pwd left unticked
        ])->assertSessionHasNoErrors();

        $entry->refresh();
        $this->assertNull($entry->is_pwd);
        $this->assertTrue($entry->is_pregnant);
    }

    public function test_sync_payload_carries_only_the_ticked_flags(): void
    {
        [$event, $center] = $this->seedBase();
        $entry = EcBoardEntry::create([
            'evacuation_center_id' => $center->id, 'evacuation_event_id' => $event->id,
            'sex' => 'female', 'age_bracket' => 'adult',
            'existing_household_remote_id' => 42, 'new_household_head_name' => 'Santos household',
            'is_pregnant' => true, 'is_lactating' => true,
        ]);

        $payload = $entry->toSyncPayload();

        $this->assertTrue($payload['is_pregnant']);
        $this->assertTrue($payload['is_lactating']);
        foreach (['is_pwd', 'is_solo_parent', 'is_indigenous_person', 'is_4ps_beneficiary'] as $flag) {
            $this->assertArrayNotHasKey($flag, $payload, "{$flag} was never ticked, so it must not be sent at all");
        }
        // The rest of the payload is untouched.
        $this->assertSame('existing', $payload['household_mode']);
        $this->assertSame(42, $payload['family_id']);
    }

    public function test_the_board_shows_pending_sectoral_counts_from_unsynced_entries_only(): void
    {
        [$event, $center] = $this->seedBase();
        $this->addEvacuee($event, $center, ['is_pregnant' => '1', 'is_pwd' => '1']);
        $this->addEvacuee($event, $center, ['sex' => 'male', 'is_pwd' => '1', 'new_household_head_name' => 'Pedro Cruz']);
        // An already-synced entry is counted in "Last known", not pending.
        EcBoardEntry::create([
            'evacuation_center_id' => $center->id, 'evacuation_event_id' => $event->id,
            'sex' => 'male', 'age_bracket' => 'adult', 'existing_household_remote_id' => 9,
            'is_pwd' => true, 'remote_id' => 100, 'synced_at' => now(),
        ]);

        $response = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));
        $response->assertOk();
        $response->assertSeeInOrder(['Sectoral group', 'On this device']);

        $rows = collect($response->viewData('pendingSectoral'))->keyBy('label');
        $this->assertSame(['label' => 'Persons with disability (PWD)', 'male' => 1, 'female' => 1], $rows['Persons with disability (PWD)']);
        $this->assertSame(['label' => 'Pregnant women', 'male' => 0, 'female' => 1], $rows['Pregnant women']);
        $this->assertSame(0, $rows['Solo parent']['male'] + $rows['Solo parent']['female']);
    }
}
