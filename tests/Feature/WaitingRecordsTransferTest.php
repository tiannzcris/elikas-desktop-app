<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\Family;
use App\Models\LocalAuth;
use App\Models\PendingCheckOut;
use App\Models\PendingListCheckIn;
use App\Models\PendingPrivateHouseFamily;
use App\Services\LocalData;
use App\Services\WaitingRecordsTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 2.1.0 part 9: waiting records to another computer, encrypted, same account, no duplicates. */
class WaitingRecordsTransferTest extends TestCase
{
    use RefreshDatabase;

    private const PASS = 'bagyo-2026-ligtas';

    private function computer(int $offset = 0): array
    {
        // Another computer has the same server ids under other local ids.
        for ($i = 0; $i < $offset; $i++) {
            EvacuationEvent::create(['remote_id' => 9000 + $i, 'name' => 'Other '.$i, 'event_type' => 'typhoon', 'status' => 'closed']);
            EvacuationCenter::create(['remote_id' => 9000 + $i, 'barangay_remote_id' => 99, 'name' => 'Other '.$i, 'status' => 'active']);
            Barangay::create(['remote_id' => 9000 + $i, 'name' => 'Other '.$i]);
        }
        $barangay = Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $event = EvacuationEvent::create(['remote_id' => 79, 'name' => 'Typhoon Rolly', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 7, 'barangay_remote_id' => 4, 'name' => 'Bacong Gym', 'status' => 'active']);

        return [$barangay, $event, $center];
    }

    private function signIn(int $userId = 1): void
    {
        LocalAuth::query()->delete();
        LocalAuth::create(['remote_user_id' => $userId, 'name' => 'Maria Santos', 'email' => 'brgy.bacong', 'role' => 'barangay_official',
            'barangay_id' => 4, 'barangay_name' => 'Bacong', 'api_token' => 't', 'logged_in_at' => now()]);
    }

    /** Four kinds of waiting records, made through the app's own screens. */
    private function makeRecords(Barangay $barangay, EvacuationEvent $event, EvacuationCenter $center): void
    {
        $this->post(route('ec-board-entries.store', $center), [
            'evacuation_event_id' => $event->id, 'sex' => 'female', 'age_bracket' => 'adult', 'household_type' => 'new',
            'new_household_barangay_id' => $barangay->id, 'new_household_head_name' => 'Rosalinda Magbanua',
        ])->assertSessionHasNoErrors();
        $this->post(route('private-houses.store'), [
            'head' => ['first_name' => 'Rosa', 'last_name' => 'Garcia', 'sex' => 'female', 'age' => 40], 'host_name' => 'Carmen Reyes', 'host_address' => 'Zone 3',
        ])->assertSessionHasNoErrors();
        PendingCheckOut::create(['evacuation_center_id' => $center->id, 'evacuation_event_id' => $event->id, 'family_remote_id' => 501, 'family_label' => 'Jose Reyes', 'status' => 'returned_home']);
        PendingListCheckIn::create(['evacuation_event_id' => $event->id, 'listed_family_remote_id' => 302, 'family_label' => 'Ana Cruz', 'members_count' => 5, 'evacuation_center_id' => $center->id]);
    }

    private function wipeComputer(): void
    {
        foreach (['download_logs', 'pending_list_check_ins', 'pending_check_outs', 'pending_private_house_families', 'ec_board_entries', 'evacuees', 'families', 'evacuation_centers', 'evacuation_events', 'barangays', 'local_auth', 'server_snapshots', 'app_state'] as $table) {
            DB::table($table)->delete();
        }
    }

    public function test_records_move_to_another_computer_and_go_out_once_with_the_same_client_refs(): void
    {
        $this->signIn();
        [$barangay, $event, $center] = $this->computer();
        $this->makeRecords($barangay, $event, $center);
        $this->assertSame(4, app(LocalData::class)->pendingCount());
        $refs = [EcBoardEntry::sole()->client_ref, PendingPrivateHouseFamily::sole()->client_ref, PendingListCheckIn::sole()->client_ref];

        // Export (browser download outside the desktop app).
        $file = $this->post(route('waiting-records.export'), ['passphrase' => self::PASS, 'passphrase_confirmation' => self::PASS])->assertOk()->getContent();
        foreach (['Rosalinda', 'Magbanua', 'Carmen', 'Ana Cruz', 'Jose Reyes'] as $plain) {
            $this->assertStringNotContainsString($plain, $file, 'The file is encrypted.');
        }
        $this->assertDatabaseHas('download_logs', ['document' => 'waiting-records']);

        // The other computer: same account, other local ids.
        $this->wipeComputer();
        $this->signIn();
        [, $event2, $center2] = $this->computer(3);
        $this->assertNotSame($center->id, $center2->id);

        $upload = UploadedFile::fake()->createWithContent('records.elikas', $file);
        $this->post(route('waiting-records.import'), ['file' => $upload, 'passphrase' => self::PASS])
            ->assertRedirect(route('sync.index'))->assertSessionHas('status', '4 records added to this computer\'s queue. They go with the next sync.');

        $this->assertSame(4, app(LocalData::class)->pendingCount());
        $entry = EcBoardEntry::sole();
        $this->assertSame([$center2->id, $event2->id], [$entry->evacuation_center_id, $entry->evacuation_event_id]);
        $this->assertSame('Rosalinda Magbanua', Family::find($entry->household_family_local_id)->name);
        $this->assertSame($entry->id, Family::find($entry->household_family_local_id)->head_ec_board_entry_id ?? $entry->id);
        $this->assertSame('Carmen Reyes', PendingPrivateHouseFamily::sole()->host_name);
        $this->assertSame([$refs[0], $refs[1], $refs[2]], [$entry->client_ref, PendingPrivateHouseFamily::sole()->client_ref, PendingListCheckIn::sole()->client_ref]);

        // Importing the same file again adds nothing.
        $this->post(route('waiting-records.import'), ['file' => UploadedFile::fake()->createWithContent('records.elikas', $file), 'passphrase' => self::PASS])
            ->assertSessionHas('status', '0 records added to this computer\'s queue (4 were already here). They go with the next sync.');
        $this->assertSame(4, app(LocalData::class)->pendingCount());

        // Sent from here with the first computer's client_refs: the server keeps each once.
        Http::fake([
            '*/evacuation-centers/7/evacuees' => Http::response(['data' => ['id' => 70], 'evacuee_id' => 700], 201),
            '*/barangays/4/private-house-families' => Http::response(['data' => ['id' => 71]], 201),
            '*/families/bulk-check-out' => Http::response(['data' => ['results' => [['people_checked_out' => 4]]]]),
            '*/families-to-evacuate/302/check-in' => Http::response(['data' => ['registered_family_id' => 72]], 201),
        ]);
        $this->post(route('sync.all'));
        $this->assertSame(0, app(LocalData::class)->pendingCount());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/evacuation-centers/7/evacuees') && $r['client_ref'] === $refs[0]);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/private-house-families') && $r['client_ref'] === $refs[1]);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/check-in') && $r['client_ref'] === $refs[2]);
    }

    public function test_another_account_cannot_import(): void
    {
        $this->signIn(1);
        [$barangay, $event, $center] = $this->computer();
        $this->makeRecords($barangay, $event, $center);
        $file = app(WaitingRecordsTransfer::class)->export(LocalAuth::current(), self::PASS)['bytes'];

        $this->wipeComputer();
        $this->signIn(2);
        $this->computer();
        $this->post(route('waiting-records.import'), ['file' => UploadedFile::fake()->createWithContent('r.elikas', $file), 'passphrase' => self::PASS])
            ->assertSessionHasErrors(['import' => 'These records were made by Maria Santos (brgy.bacong). Sign in as that account on this computer to import them.']);
        $this->assertSame(0, app(LocalData::class)->pendingCount());
    }

    public function test_a_wrong_passphrase_or_missing_reference_data_imports_nothing(): void
    {
        $this->signIn();
        [$barangay, $event, $center] = $this->computer();
        $this->makeRecords($barangay, $event, $center);
        $file = app(WaitingRecordsTransfer::class)->export(LocalAuth::current(), self::PASS)['bytes'];
        $this->wipeComputer();
        $this->signIn();

        $this->post(route('waiting-records.import'), ['file' => UploadedFile::fake()->createWithContent('r.elikas', $file), 'passphrase' => 'not-it-at-all'])
            ->assertSessionHasErrors(['import' => 'The passphrase is wrong, or the file is damaged.']);

        // This computer has not refreshed reference data yet.
        $this->post(route('waiting-records.import'), ['file' => UploadedFile::fake()->createWithContent('r.elikas', $file), 'passphrase' => self::PASS])
            ->assertSessionHasErrors('import');
        $this->assertSame(0, app(LocalData::class)->pendingCount());
    }

    public function test_the_export_needs_a_confirmed_passphrase_and_something_to_export(): void
    {
        $this->signIn();
        $this->computer();
        $this->post(route('waiting-records.export'), ['passphrase' => 'short', 'passphrase_confirmation' => 'short'])->assertSessionHasErrors('passphrase');
        $this->post(route('waiting-records.export'), ['passphrase' => self::PASS, 'passphrase_confirmation' => 'different-one'])->assertSessionHasErrors('passphrase');
        $this->post(route('waiting-records.export'), ['passphrase' => self::PASS, 'passphrase_confirmation' => self::PASS])
            ->assertSessionHasErrors(['export' => 'Nothing is waiting to be sent: there is nothing to export.']);
    }
}
