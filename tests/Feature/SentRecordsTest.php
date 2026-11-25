<?php

namespace Tests\Feature;

use App\Http\Controllers\SentRecordController;
use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\PendingHandout;
use App\Models\Purok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Sync > Sent: what this device sent, and correcting a sent registration on the server. */
class SentRecordsTest extends TestCase
{
    use RefreshDatabase;

    private EvacuationCenter $center;

    private EvacuationEvent $event;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'Ana Cruz', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
        $this->barangay = Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $this->event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon', 'event_type' => 'typhoon', 'status' => 'active']);
        $this->center = EvacuationCenter::create(['remote_id' => 7, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'active']);
    }

    private function add(string $name): EcBoardEntry
    {
        $this->post(route('ec-board-entries.store', $this->center), [
            'evacuation_event_id' => $this->event->id, 'sex' => 'female', 'age_bracket' => 'adult', 'household_type' => 'new',
            'new_household_barangay_id' => $this->barangay->id, 'new_household_head_name' => $name,
        ])->assertSessionHasNoErrors();

        return EcBoardEntry::latest('id')->first();
    }

    /** A registration already on the server: person 501 in family 77. */
    private function sentEntry(): EcBoardEntry
    {
        return EcBoardEntry::create(['evacuation_center_id' => $this->center->id, 'evacuation_event_id' => $this->event->id,
            'sex' => 'female', 'age_bracket' => 'adult', 'new_household_head_name' => 'Reyes', 'head_is_self' => true,
            'remote_id' => 501, 'family_remote_id' => 77, 'synced_at' => now()->subHour(), 'sent_by' => 'Ana Cruz']);
    }

    /** GET /families/77 as the server answers it (FamilyResource with members). */
    private function family(array $overrides = []): array
    {
        return array_merge([
            'id' => 77, 'name' => 'Reyes', 'barangay' => ['id' => 4, 'name' => 'Bacong'], 'purok_id' => null, 'purok' => null,
            'head_of_family' => ['id' => 501, 'full_name' => 'Reyes'], 'is_single_headed' => null, 'is_child_headed' => false, 'head_sex' => 'female',
            'members' => [[
                'id' => 501, 'family_id' => 77, 'sex' => 'female', 'age_bracket' => 'adult',
                'sectoral' => ['is_pwd' => false, 'is_pregnant' => true, 'is_lactating' => false, 'is_solo_parent' => false,
                    'is_indigenous_person' => false, 'is_4ps_beneficiary' => false],
            ]],
        ], $overrides);
    }

    public function test_sent_records_are_listed_after_a_sync_with_what_where_when_and_who(): void
    {
        $this->add('Reyes');
        Http::fake(['*/evacuees' => Http::response(['data' => ['id' => 77], 'evacuee_id' => 501], 201)]);

        $this->post(route('sync.all'))->assertSessionHas('status', '1 record sent.');

        $entry = EcBoardEntry::first();
        $this->assertSame([501, 77, 'Ana Cruz'], [(int) $entry->remote_id, (int) $entry->family_remote_id, $entry->sent_by]);
        $this->get(route('sync.index'))->assertOk()
            ->assertSee('To send (0)')->assertSee('Sent (1)')
            ->assertSee('data-sent-item="entry:'.$entry->id.'"', false)
            ->assertSeeInOrder(['Adult, female · Reyes', 'Gym', 'Ana Cruz'])
            ->assertSee(route('sync.sent.edit', $entry), false);
    }

    public function test_the_sent_list_is_for_the_selected_event_newest_first_and_kinds_without_a_correction_say_where_to_see_them(): void
    {
        $older = $this->sentEntry();
        $older->update(['synced_at' => now()->subHours(3)]);
        PendingHandout::create(['client_ref' => (string) \Illuminate\Support\Str::uuid(), 'evacuation_event_id' => $this->event->id,
            'round_remote_id' => 3, 'round_number' => 1, 'family_remote_id' => 77, 'family_label' => 'Santos',
            'handout_remote_id' => 9, 'synced_at' => now()->subHour()]);
        $other = EvacuationEvent::create(['remote_id' => 2, 'name' => 'Flood', 'event_type' => 'flood', 'status' => 'monitoring']);
        EcBoardEntry::create(['evacuation_center_id' => $this->center->id, 'evacuation_event_id' => $other->id, 'sex' => 'male',
            'age_bracket' => 'adult', 'new_household_head_name' => 'Other event', 'remote_id' => 9, 'synced_at' => now()]);
        // Older than the list goes back.
        EcBoardEntry::create(['evacuation_center_id' => $this->center->id, 'evacuation_event_id' => $this->event->id, 'sex' => 'male',
            'age_bracket' => 'adult', 'new_household_head_name' => 'Long ago', 'remote_id' => 8, 'synced_at' => now()->subDays(20)]);

        $this->get(route('sync.index'))->assertOk()
            ->assertSee('Sent (2)')
            ->assertSeeInOrder(['Relief box: Santos (Round 1)', 'See it in', 'Relief boxes', 'Adult, female · Reyes', 'Edit'])
            ->assertDontSee('Other event')->assertDontSee('Long ago');
    }

    public function test_edit_after_sync_opens_the_form_from_the_server_and_saves_through_its_endpoints(): void
    {
        $entry = $this->sentEntry();
        Purok::create(['remote_id' => 31, 'barangay_remote_id' => 4, 'name' => 'Purok 1']);
        Http::fake([
            '*/families/77' => Http::response(['data' => $this->family()]),
            '*/evacuees/501' => Http::response(['data' => ['id' => 501]]),
            '*/families/77/household' => Http::response(['data' => ['id' => 77]]),
        ]);

        $this->get(route('sync.sent.edit', $entry))->assertOk()
            ->assertSee('Correct a sent record')->assertSee('Reyes')->assertSee('data-age-group', false)
            ->assertSee('name="is_pregnant" value="1" checked', false)
            ->assertSee('Purok 1');

        $this->patch(route('sync.sent.update', $entry), [
            'sex' => 'female', 'is_pregnant' => '1', 'is_pwd' => '1', 'head_is_self' => '1', 'is_single_headed' => '1', 'purok_id' => '31',
            'original' => ['head_id' => '501', 'is_single_headed' => '', 'head_sex' => '', 'head_is_minor' => '', 'purok_id' => ''],
        ])->assertRedirect(route('sync.index').'#sent')->assertSessionHas('status', 'Corrected on the server.');

        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/evacuees/501')
            && $r['sex'] === 'female' && $r['is_pwd'] === true && $r['is_pregnant'] === true && $r['is_solo_parent'] === false);
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/families/77/household')
            && $r['head_of_family_evacuee_id'] === 501 && $r['is_single_headed'] === true && $r['purok_id'] === 31);
        $this->assertTrue($entry->fresh()->is_pwd);
    }

    public function test_an_unchanged_family_is_not_sent_and_a_male_carries_no_pregnancy(): void
    {
        $entry = $this->sentEntry();
        Http::fake(['*/evacuees/501' => Http::response(['data' => ['id' => 501]])]);

        $this->patch(route('sync.sent.update', $entry), [
            'sex' => 'male', 'is_pregnant' => '1', 'head_is_self' => '1',
            'original' => ['head_id' => '501', 'is_single_headed' => '', 'head_sex' => '', 'head_is_minor' => ''],
        ])->assertSessionHas('status', 'Corrected on the server.');

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/evacuees/501') && $r['sex'] === 'male' && $r['is_pregnant'] === false);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/household'));
    }

    public function test_the_servers_422_is_shown(): void
    {
        $entry = $this->sentEntry();
        Http::fake(['*/evacuees/501' => Http::response(['success' => false, 'message' => 'The given data was invalid.',
            'errors' => ['sex' => ['The selected sex is invalid.']]], 422)]);

        $this->from(route('sync.sent.edit', $entry))
            ->patch(route('sync.sent.update', $entry), ['sex' => 'female', 'original' => ['head_id' => '501']])
            ->assertRedirect(route('sync.sent.edit', $entry))
            ->assertSessionHasErrors(['sent' => 'The selected sex is invalid.']);
    }

    public function test_a_refused_family_says_the_person_was_saved(): void
    {
        $entry = $this->sentEntry();
        Http::fake([
            '*/evacuees/501' => Http::response(['data' => ['id' => 501]]),
            '*/families/77/household' => Http::response(['success' => false,
                'message' => "This family already has a head on file -- choose another member to reassign it; it can't be left with no head."], 422),
        ]);

        $this->from(route('sync.sent.edit', $entry))
            ->patch(route('sync.sent.update', $entry), ['sex' => 'female', 'original' => ['head_id' => '501']])
            ->assertSessionHasErrors(['sent' => "The person was corrected, but not the family. This family already has a head on file -- choose another member to reassign it; it can't be left with no head."]);
    }

    public function test_offline_the_edit_is_refused(): void
    {
        $entry = $this->sentEntry();
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->get(route('sync.sent.edit', $entry))
            ->assertRedirect(route('sync.index').'#sent')
            ->assertSessionHasErrors(['sent' => SentRecordController::OFFLINE]);
        $this->from(route('sync.sent.edit', $entry))
            ->patch(route('sync.sent.update', $entry), ['sex' => 'male'])
            ->assertSessionHasErrors(['sent' => SentRecordController::OFFLINE]);
        $this->assertSame('female', $entry->fresh()->sex);
    }

    public function test_the_old_edit_of_a_synced_entry_goes_to_the_sent_edit(): void
    {
        $entry = $this->sentEntry();

        $this->get(route('ec-board-entries.edit', $entry))->assertRedirect(route('sync.sent.edit', $entry));
        $this->put(route('ec-board-entries.update', $entry), [
            'evacuation_event_id' => $this->event->id, 'sex' => 'male', 'age_bracket' => 'adult', 'household_type' => 'new',
            'new_household_barangay_id' => $this->barangay->id, 'new_household_head_name' => 'Reyes',
        ])->assertRedirect(route('sync.sent.edit', $entry));
        $this->assertSame('female', $entry->fresh()->sex);
    }

    public function test_an_unsent_entry_is_still_edited_on_this_device(): void
    {
        $entry = $this->add('Reyes');

        $this->get(route('sync.sent.edit', $entry))->assertRedirect(route('ec-board-entries.edit', $entry));
    }

    public function test_auto_sync_says_where_the_records_went(): void
    {
        $this->get(route('dashboard'))->assertSee('data-sent-url="'.route('sync.index').'"', false);
        $this->assertStringContainsString('sent. See Sync > Sent', file_get_contents(resource_path('js/app.js')));
    }
}
