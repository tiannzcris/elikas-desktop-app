<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\Evacuee;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\Family;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Household-head questions on Add Evacuee -- the desktop counterpart of the
 * web dashboard's own (elikas-backend commits 16d6501, 3804847, c395dc8),
 * mirroring its test matrix: this person is the head; someone else is the
 * head; the real head arriving later via "Already here"; an existing head
 * never replaced; and the exact addEvacuee() payloads, which were verified
 * against the running central server before this was built.
 */
class EcBoardHouseholdHeadTest extends TestCase
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

    private function addNewHousehold(EvacuationEvent $event, EvacuationCenter $center, array $overrides = []): EcBoardEntry
    {
        $this->post(route('ec-board-entries.store', $center), array_merge([
            'evacuation_event_id' => $event->id,
            'sex' => 'female',
            'age_bracket' => 'adult',
            'household_type' => 'new', 'new_household_barangay_id' => \App\Models\Barangay::value('id'),
            'new_household_head_name' => 'Maria Santos',
        ], $overrides))->assertSessionHasNoErrors();

        return EcBoardEntry::latest('id')->firstOrFail();
    }

    private function addToExisting(EvacuationEvent $event, EvacuationCenter $center, string $household, array $overrides = []): EcBoardEntry
    {
        $this->post(route('ec-board-entries.store', $center), array_merge([
            'evacuation_event_id' => $event->id,
            'sex' => 'male',
            'age_bracket' => 'adult',
            'household_type' => 'existing',
            'household_family_local_id' => $household,
        ], $overrides))->assertSessionHasNoErrors();

        return EcBoardEntry::latest('id')->firstOrFail();
    }

    // -----------------------------------------------------------------
    // Path 1: this person is the household head
    // -----------------------------------------------------------------

    public function test_person_is_head_links_them_and_their_own_sex_and_age_describe_the_head(): void
    {
        [$event, $center] = $this->seedBase();

        $entry = $this->addNewHousehold($event, $center, [
            'sex' => 'male', 'age_bracket' => 'teenage', 'head_is_self' => '1', 'is_single_headed' => '1',
            // Sent by a hidden field -- must be ignored when this person is the head.
            'head_sex' => 'female', 'head_is_minor' => '0',
        ]);
        $family = $entry->household()->with('headEntry')->firstOrFail();

        $this->assertTrue($entry->head_is_self);
        $this->assertSame($entry->id, $family->head_ec_board_entry_id);
        $this->assertTrue($family->hasLinkedHead());
        $this->assertNull($family->head_sex);
        $this->assertNull($family->head_is_minor);
        $this->assertTrue($family->isSingleHeaded());
        $this->assertSame('male', $family->headSex());
        $this->assertTrue($family->isChildHeaded(), 'A teenage head is a minor -- child-headed.');
        // A head member row, so the household reads by its head's name.
        $this->assertDatabaseHas('evacuees', ['family_id' => $family->id, 'first_name' => 'Maria', 'last_name' => 'Santos', 'sex' => 'male', 'is_head_of_family' => true]);

        $payload = $entry->fresh()->toSyncPayload();
        $this->assertSame('new', $payload['household_mode']);
        $this->assertSame('Maria Santos', $payload['family_name']);
        $this->assertTrue($payload['head_is_self']);
        $this->assertTrue($payload['is_single_headed']);
        $this->assertArrayNotHasKey('head_sex', $payload);
        $this->assertArrayNotHasKey('head_is_minor', $payload);
    }

    // -----------------------------------------------------------------
    // Path 2: someone else is the household head
    // -----------------------------------------------------------------

    public function test_someone_else_is_head_stores_the_head_answers_and_links_nobody(): void
    {
        [$event, $center] = $this->seedBase();

        $entry = $this->addNewHousehold($event, $center, [
            'sex' => 'female', 'age_bracket' => 'school_age',
            'is_single_headed' => '1', 'head_sex' => 'male', 'head_is_minor' => '0',
        ]);
        $family = $entry->household()->firstOrFail();

        $this->assertFalse($entry->head_is_self);
        $this->assertNull($family->head_ec_board_entry_id);
        $this->assertFalse($family->hasLinkedHead());
        $this->assertSame('Maria Santos', $family->name);
        $this->assertSame('male', $family->headSex());
        $this->assertFalse($family->isChildHeaded());
        $this->assertTrue($family->isSingleHeaded());
        // No head member is invented with a guessed sex.
        $this->assertSame(0, $family->evacuees()->count());

        $payload = $entry->fresh()->toSyncPayload();
        $this->assertFalse($payload['head_is_self']);
        $this->assertTrue($payload['is_single_headed']);
        $this->assertSame('male', $payload['head_sex']);
        $this->assertFalse($payload['head_is_minor']);
        $this->assertSame('Maria Santos', $payload['family_name']);
    }

    public function test_not_yet_known_is_stored_as_null_never_as_no(): void
    {
        [$event, $center] = $this->seedBase();

        $entry = $this->addNewHousehold($event, $center, ['is_single_headed' => '', 'head_sex' => '', 'head_is_minor' => '']);
        $family = $entry->household()->firstOrFail();

        $this->assertNull($family->is_single_headed);
        $this->assertNull($family->head_sex);
        $this->assertNull($family->head_is_minor);
        $this->assertNull($family->isChildHeaded());

        $payload = $entry->fresh()->toSyncPayload();
        $this->assertArrayHasKey('is_single_headed', $payload);
        $this->assertNull($payload['is_single_headed']);
        $this->assertNull($payload['head_sex']);
        $this->assertNull($payload['head_is_minor']);
    }

    public function test_an_invalid_head_sex_is_rejected(): void
    {
        [$event, $center] = $this->seedBase();

        $this->post(route('ec-board-entries.store', $center), [
            'evacuation_event_id' => $event->id, 'sex' => 'male', 'age_bracket' => 'adult',
            'household_type' => 'new', 'new_household_barangay_id' => \App\Models\Barangay::value('id'), 'new_household_head_name' => 'X', 'head_sex' => 'unknown',
        ])->assertSessionHasErrors('head_sex');

        $this->assertSame(0, Family::count());
    }

    // -----------------------------------------------------------------
    // Path 3: the real head arriving later, via "Already here"
    // -----------------------------------------------------------------

    public function test_a_later_arriving_head_is_linked_through_already_here(): void
    {
        [$event, $center] = $this->seedBase();
        $first = $this->addNewHousehold($event, $center, ['sex' => 'female', 'age_bracket' => 'school_age', 'head_sex' => 'male', 'head_is_minor' => '1']);
        $family = $first->household()->firstOrFail();

        $head = $this->addToExisting($event, $center, (string) $family->id, ['sex' => 'female', 'age_bracket' => 'adult', 'head_is_self' => '1']);
        $family->refresh()->load('headEntry');

        $this->assertTrue($head->head_is_self);
        $this->assertSame($head->id, $family->head_ec_board_entry_id);
        // The linked head's own sex and age now win over the old answers...
        $this->assertSame('female', $family->headSex());
        $this->assertFalse($family->isChildHeaded());
        // ...which stay stored, so unlinking would restore them.
        $this->assertSame('male', $family->head_sex);
        $this->assertTrue($family->head_is_minor);

        // At sync time the household's own creating entry goes first and
        // stamps its remote id (see the full-cycle test below).
        $family->update(['remote_id' => 485, 'synced_at' => now()]);
        $payload = $head->fresh()->toSyncPayload();
        $this->assertSame('existing', $payload['household_mode']);
        $this->assertTrue($payload['head_is_self']);
        $this->assertArrayNotHasKey('head_sex', $payload);
        $this->assertArrayNotHasKey('is_single_headed', $payload);
    }

    public function test_an_existing_head_is_never_replaced_from_already_here(): void
    {
        [$event, $center] = $this->seedBase();
        $first = $this->addNewHousehold($event, $center, ['head_is_self' => '1']);
        $family = $first->household()->firstOrFail();

        $second = $this->addToExisting($event, $center, (string) $family->id, ['head_is_self' => '1']);

        $this->assertSame($first->id, $family->fresh()->head_ec_board_entry_id);
        $this->assertFalse($second->head_is_self);
        $family->update(['remote_id' => 485, 'synced_at' => now()]);
        $this->assertArrayNotHasKey('head_is_self', $second->fresh()->toSyncPayload());
    }

    public function test_a_fully_registered_family_is_never_offered_a_head(): void
    {
        [$event, $center] = $this->seedBase();
        $family = Family::create([
            'barangay_id' => Barangay::first()->id, 'evacuation_event_id' => $event->id, 'evacuation_center_id' => $center->id,
            'displacement_type' => 'inside_center', 'remote_id' => 50, 'synced_at' => now(),
        ]);
        Evacuee::create(['family_id' => $family->id, 'first_name' => 'Pedro', 'last_name' => 'Cruz', 'sex' => 'male', 'is_head_of_family' => true]);

        $this->assertTrue($family->hasLinkedHead());

        $page = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));
        $page->assertSee('value="'.$family->id.'"'."\n".'                            data-head-open="0"', false);

        $entry = $this->addToExisting($event, $center, (string) $family->id, ['head_is_self' => '1']);
        $this->assertFalse($entry->head_is_self);
    }

    public function test_a_household_known_only_on_the_server_records_the_wish_and_the_server_decides(): void
    {
        [$event, $center] = $this->seedBase();

        $entry = $this->addToExisting($event, $center, 'remote-88', ['head_is_self' => '1', 'household_label' => 'Remote Household']);

        $this->assertTrue($entry->head_is_self);
        $payload = $entry->toSyncPayload();
        $this->assertSame(88, $payload['family_id']);
        $this->assertTrue($payload['head_is_self']);
    }

    public function test_households_refresh_reports_whether_each_server_household_has_a_head(): void
    {
        [$event, $center] = $this->seedBase();

        Http::fake(['*/evacuation-centers/1/families*' => Http::response(['data' => [
            ['id' => 5, 'name' => 'Headless', 'head_of_family' => null],
            ['id' => 6, 'name' => 'Headed', 'head_of_family' => ['id' => 60, 'full_name' => 'Ana Reyes']],
        ]])]);

        $rows = collect($this->get(route('evacuation-centers.households-refresh', $center).'?event='.$event->id)->json('households'))->keyBy('value');

        $this->assertFalse($rows['remote-5']['head_linked']);
        $this->assertTrue($rows['remote-6']['head_linked']);
    }

    // -----------------------------------------------------------------
    // Editing and deleting pending entries keep the link correct
    // -----------------------------------------------------------------

    public function test_deleting_the_linked_head_entry_unlinks_the_head_and_restores_the_answers(): void
    {
        [$event, $center] = $this->seedBase();
        $first = $this->addNewHousehold($event, $center, ['head_sex' => 'male', 'head_is_minor' => '1']);
        $family = $first->household()->firstOrFail();
        $head = $this->addToExisting($event, $center, (string) $family->id, ['sex' => 'female', 'head_is_self' => '1']);

        $this->delete(route('ec-board-entries.destroy', $head));

        $family->refresh();
        $this->assertNull($family->head_ec_board_entry_id);
        $this->assertSame('male', $family->headSex());
        $this->assertTrue($family->isChildHeaded());
    }

    public function test_editing_the_household_creating_entry_updates_the_households_answers(): void
    {
        [$event, $center] = $this->seedBase();
        $entry = $this->addNewHousehold($event, $center, ['head_is_self' => '1', 'is_single_headed' => '']);
        $family = $entry->household()->firstOrFail();
        $this->assertSame(1, $family->evacuees()->count());

        $this->put(route('ec-board-entries.update', $entry), [
            'evacuation_event_id' => $event->id, 'sex' => 'female', 'age_bracket' => 'adult',
            'household_type' => 'new', 'new_household_barangay_id' => \App\Models\Barangay::value('id'), 'new_household_head_name' => 'Maria Santos-Reyes',
            'is_single_headed' => '0', 'head_sex' => 'male', 'head_is_minor' => '0',
        ])->assertSessionHasNoErrors();

        $family->refresh();
        $this->assertSame('Maria Santos-Reyes', $family->name);
        $this->assertFalse($family->is_single_headed);
        $this->assertNull($family->head_ec_board_entry_id, 'No longer the head -- unlinked.');
        $this->assertSame('male', $family->head_sex);
        $this->assertSame(0, $family->evacuees()->count(), 'The head member row goes with the link.');
        // Still tied to the household it created.
        $this->assertSame($family->id, $entry->fresh()->household_family_local_id);
        $this->assertTrue($entry->fresh()->originated_household);
    }

    public function test_moving_the_head_to_another_household_unlinks_them_from_the_old_one(): void
    {
        [$event, $center] = $this->seedBase();
        $a = $this->addNewHousehold($event, $center, ['new_household_head_name' => 'Household A'])->household()->firstOrFail();
        $b = $this->addNewHousehold($event, $center, ['new_household_head_name' => 'Household B'])->household()->firstOrFail();
        $head = $this->addToExisting($event, $center, (string) $a->id, ['head_is_self' => '1']);
        $this->assertSame($head->id, $a->fresh()->head_ec_board_entry_id);

        $this->put(route('ec-board-entries.update', $head), [
            'evacuation_event_id' => $event->id, 'sex' => 'male', 'age_bracket' => 'adult',
            'household_type' => 'existing', 'household_family_local_id' => (string) $b->id,
        ])->assertSessionHasNoErrors();

        $this->assertNull($a->fresh()->head_ec_board_entry_id);
        $this->assertNull($b->fresh()->head_ec_board_entry_id);
        $this->assertFalse($head->fresh()->head_is_self);
    }

    // -----------------------------------------------------------------
    // Counting, display, and the "head not yet linked" reminder
    // -----------------------------------------------------------------

    public function test_pending_child_and_single_headed_counts_follow_the_servers_rule(): void
    {
        [$event, $center] = $this->seedBase();
        // Single-headed, head a male minor (someone else).
        $this->addNewHousehold($event, $center, ['is_single_headed' => '1', 'head_sex' => 'male', 'head_is_minor' => '1']);
        // Single-headed, head this female adult.
        $this->addNewHousehold($event, $center, ['head_is_self' => '1', 'is_single_headed' => '1']);
        // Single-headed but head's sex unknown -- counted in neither column.
        $this->addNewHousehold($event, $center, ['is_single_headed' => '1']);

        $rows = collect($this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]))->viewData('pendingSectoral'))->keyBy('label');

        $this->assertSame(['label' => 'Single-headed family', 'male' => 1, 'female' => 1], $rows['Single-headed family']);
        $this->assertSame(['label' => 'Child-headed family', 'male' => 1, 'female' => 0], $rows['Child-headed family']);
    }

    public function test_the_form_offers_the_head_tick_only_for_a_household_with_no_head(): void
    {
        [$event, $center] = $this->seedBase();
        $headless = $this->addNewHousehold($event, $center, ['new_household_head_name' => 'Headless'])->household()->firstOrFail();
        $headed = $this->addNewHousehold($event, $center, ['new_household_head_name' => 'Headed', 'head_is_self' => '1'])->household()->firstOrFail();

        $page = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]));

        $page->assertSee('value="'.$headless->id.'"'."\n".'                            data-head-open="1"', false);
        $page->assertSee('value="'.$headed->id.'"'."\n".'                            data-head-open="0"', false);
        $page->assertSeeInOrder(['Who is this person?', 'Family', 'About the actual family head', 'Sectoral details', 'Will be recorded', 'Add evacuee (offline)']);
    }

    public function test_the_family_card_shows_head_not_yet_linked_until_a_head_is_linked(): void
    {
        [$event, $center] = $this->seedBase();
        $entry = $this->addNewHousehold($event, $center, ['is_single_headed' => '1', 'head_sex' => 'male', 'head_is_minor' => '0']);
        $family = $entry->household()->firstOrFail();

        $page = $this->get(route('families.index'));
        $page->assertSee('Head not yet linked. Counts use the answers given for the head (male, not a minor) until a member is linked.');
        $page->assertSee('Family details: single-headed yes, child-headed no (male)');

        $this->addToExisting($event, $center, (string) $family->id, ['sex' => 'female', 'head_is_self' => '1']);

        $page = $this->get(route('families.index'));
        $page->assertDontSee('Head not yet linked');
        $page->assertSee('Family details: single-headed yes, child-headed no (female)');
    }

    // -----------------------------------------------------------------
    // Offline add, then one sync run
    // -----------------------------------------------------------------

    /**
     * New household (someone else is head) and its real head arriving
     * later, both added offline, synced in ONE run: the creating entry goes
     * first with the household answers, then the linking entry against the
     * family id that first call just returned.
     */
    public function test_a_full_offline_add_then_sync_cycle_sends_the_exact_head_payloads_in_order(): void
    {
        [$event, $center] = $this->seedBase();
        $first = $this->addNewHousehold($event, $center, ['sex' => 'female', 'age_bracket' => 'school_age', 'is_single_headed' => '1', 'head_sex' => 'male', 'head_is_minor' => '0']);
        $family = $first->household()->firstOrFail();
        $head = $this->addToExisting($event, $center, (string) $family->id, ['sex' => 'male', 'age_bracket' => 'adult', 'head_is_self' => '1']);

        Http::fakeSequence('*/evacuation-centers/1/evacuees')
            ->push(['data' => ['id' => 485], 'evacuee_id' => 1769], 201)
            ->push(['data' => ['id' => 485], 'evacuee_id' => 1770], 201);

        $this->post(route('families.sync'))->assertSessionHas('status', '2 record(s) synced successfully.');

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0]->data())->values();
        $this->assertCount(2, $sent);
        $this->assertSame([
            'evacuation_event_id' => 7, 'sex' => 'female', 'age_bracket' => 'school_age',
            'household_mode' => 'new', 'family_id' => null, 'barangay_id' => 1, 'family_name' => 'Maria Santos',
            'head_is_self' => false, 'is_single_headed' => true, 'head_sex' => 'male', 'head_is_minor' => false,
        ], $sent[0]);
        $this->assertSame([
            'evacuation_event_id' => 7, 'sex' => 'male', 'age_bracket' => 'adult',
            'household_mode' => 'existing', 'family_id' => 485, 'barangay_id' => null, 'family_name' => null,
            'head_is_self' => true,
        ], $sent[1]);

        $this->assertSame(485, $family->fresh()->remote_id);
        $this->assertNotNull($head->fresh()->synced_at);
        // Each entry records its OWN evacuee's id -- the server's top-level
        // evacuee_id, not the shared family id.
        $this->assertSame(1769, $first->fresh()->remote_id);
        $this->assertSame(1770, $head->fresh()->remote_id);
    }
}
