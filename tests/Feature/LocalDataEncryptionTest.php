<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\CenterFamilySnapshot;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\Evacuee;
use App\Models\Family;
use App\Models\LocalAuth;
use App\Models\PendingPrivateHouseFamily;
use App\Models\ServerSnapshot;
use App\Security\LocalDataEncryption;
use App\Security\LocalDataKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2.1.0 part 8: personal data encrypted at rest with this computer's key,
 * and a 2.0.x database moved to it with nothing lost.
 */
class LocalDataEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private EvacuationEvent $event;

    private EvacuationCenter $center;

    private Barangay $barangay;

    protected function setUp(): void
    {
        parent::setUp();
        $this->barangay = Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $this->event = EvacuationEvent::create(['remote_id' => 79, 'name' => 'Typhoon Rolly', 'event_type' => 'typhoon', 'status' => 'active']);
        $this->center = EvacuationCenter::create(['remote_id' => 7, 'barangay_remote_id' => 4, 'name' => 'Bacong Gym', 'status' => 'active']);
    }

    /** Every raw value in the database, as one string. */
    private function rawDatabase(): string
    {
        $all = '';
        foreach (array_keys(LocalDataEncryption::COLUMNS) as $table) {
            $all .= json_encode(DB::table($table)->get());
        }

        return $all;
    }

    public function test_personal_data_is_stored_encrypted_and_reads_back(): void
    {
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official', 'api_token' => 'secret-token-123', 'logged_in_at' => now()]);
        $family = Family::create(['barangay_id' => $this->barangay->id, 'evacuation_event_id' => $this->event->id, 'evacuation_center_id' => $this->center->id,
            'displacement_type' => 'inside_center', 'name' => 'Rosalinda Magbanua', 'home_address' => 'Zone 3, near the chapel']);
        Evacuee::create(['family_id' => $family->id, 'first_name' => 'Rosalinda', 'last_name' => 'Magbanua', 'sex' => 'female', 'date_of_birth' => '1980-05-01', 'contact_number' => '09171234567']);
        CenterFamilySnapshot::create(['evacuation_center_id' => $this->center->id, 'evacuation_event_id' => $this->event->id, 'fetched_at' => now(),
            'families' => [['id' => 1, 'name' => 'Teodoro Villanueva']]]);
        ServerSnapshot::keep('transfers.1', ['family_list' => [['head' => 'Crisanto Dimaculangan']]]);

        $raw = $this->rawDatabase();
        foreach (['secret-token-123', 'Rosalinda', 'Magbanua', 'near the chapel', '09171234567', '1980-05-01', 'Teodoro', 'Crisanto'] as $plain) {
            $this->assertStringNotContainsString($plain, $raw, "{$plain} is stored in plain text");
        }

        $this->assertSame('secret-token-123', LocalAuth::current()->api_token);
        $this->assertSame('Rosalinda Magbanua', $family->fresh()->name);
        $this->assertSame('1980-05-01', Evacuee::first()->date_of_birth->toDateString());
        $this->assertSame('Teodoro Villanueva', CenterFamilySnapshot::first()->families[0]['name']);
        $this->assertSame('Crisanto Dimaculangan', ServerSnapshot::named('transfers.1')->payload['family_list'][0]['head']);
        $this->assertSame(0, app(LocalDataEncryption::class)->plainCount());
    }

    public function test_a_2_0_database_is_encrypted_with_nothing_lost(): void
    {
        // As a 2.0.x device left it: plain text, written straight to the tables.
        DB::table('local_auth')->insert(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official', 'api_token' => 'old-token', 'logged_in_at' => now()]);
        $familyId = DB::table('families')->insertGetId(['barangay_id' => $this->barangay->id, 'evacuation_event_id' => $this->event->id, 'evacuation_center_id' => $this->center->id,
            'displacement_type' => 'inside_center', 'name' => 'Santos', 'home_address' => 'Purok 2', 'created_via_ec_board' => 1, 'client_ref' => 'a1']);
        DB::table('evacuees')->insert(['family_id' => $familyId, 'first_name' => 'Maria', 'last_name' => 'Santos', 'sex' => 'female', 'date_of_birth' => '1990-02-03 00:00:00']);
        DB::table('ec_board_entries')->insert(['evacuation_center_id' => $this->center->id, 'evacuation_event_id' => $this->event->id, 'sex' => 'male',
            'age_bracket' => 'adult', 'new_household_head_name' => 'Reyes', 'client_ref' => 'b2']);
        DB::table('center_family_snapshots')->insert(['evacuation_center_id' => $this->center->id, 'evacuation_event_id' => $this->event->id,
            'families' => json_encode([['id' => 5, 'name' => 'Dela Cruz', 'here_count' => 3]]), 'fetched_at' => now()]);
        DB::table('pending_private_house_families')->insert(['client_ref' => 'c3', 'evacuation_event_id' => $this->event->id, 'barangay_remote_id' => 4,
            'head' => json_encode(['first_name' => 'Rosa', 'last_name' => 'Garcia', 'sex' => 'female', 'age' => 40]), 'members' => json_encode([]),
            'host_name' => 'Carmen Reyes', 'host_address' => 'Zone 3']);
        DB::table('server_snapshots')->insert(['key' => 'monitoring.overview', 'payload' => json_encode(['figures' => ['persons' => 1234]]), 'fetched_at' => now()]);
        DB::table('evacuees')->insert(['family_id' => $familyId, 'first_name' => 'Ana', 'last_name' => 'Santos', 'sex' => 'female', 'middle_name' => null]);

        $rowsBefore = collect(array_keys(LocalDataEncryption::COLUMNS))->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
        $encryption = app(LocalDataEncryption::class);
        $this->assertGreaterThan(0, $encryption->plainCount());

        $counts = $encryption->encryptExisting();

        // token 1, family 2, evacuees 3 + 2, entry 1, snapshot 1, private house 4, server copy 1.
        $this->assertSame(15, array_sum($counts));
        $this->assertSame(0, $encryption->plainCount());
        $this->assertSame($rowsBefore, collect(array_keys(LocalDataEncryption::COLUMNS))->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all(), 'No row was added or lost.');
        foreach (['old-token', 'Maria', 'Purok 2', 'Reyes', 'Dela Cruz', 'Carmen', 'Garcia', '1990-02-03'] as $plain) {
            $this->assertStringNotContainsString($plain, $this->rawDatabase());
        }

        // Every value reads back exactly as it was.
        $this->assertSame('old-token', LocalAuth::current()->api_token);
        $family = Family::find($familyId);
        $this->assertSame(['Santos', 'Purok 2'], [$family->name, $family->home_address]);
        $this->assertSame(['Maria', 'Santos', '1990-02-03'], [Evacuee::first()->first_name, Evacuee::first()->last_name, Evacuee::first()->date_of_birth->toDateString()]);
        $this->assertNull(Evacuee::orderByDesc('id')->first()->middle_name);
        $this->assertSame('Reyes', EcBoardEntry::first()->new_household_head_name);
        $this->assertSame([['id' => 5, 'name' => 'Dela Cruz', 'here_count' => 3]], CenterFamilySnapshot::first()->families);
        $ph = PendingPrivateHouseFamily::first();
        $this->assertSame(['Rosa', 'Carmen Reyes', 'Zone 3'], [$ph->head['first_name'], $ph->host_name, $ph->host_address]);
        $this->assertSame(1234, ServerSnapshot::named('monitoring.overview')->payload['figures']['persons']);
        // Unchanged columns stay as they were (client_ref, ids, flags).
        $this->assertSame('a1', $family->client_ref);

        // A second run changes nothing.
        $this->assertSame([], $encryption->encryptExisting());
        $this->assertSame('Santos', Family::find($familyId)->name);
    }

    public function test_the_backup_is_made_first_encrypted_and_restores_the_whole_database(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'elikas-db');
        $pdo = new \PDO('sqlite:'.$file);
        $pdo->exec("CREATE TABLE families (id INTEGER PRIMARY KEY, name TEXT); INSERT INTO families (name) VALUES ('Santos'), ('Reyes');");
        $pdo = null;
        config(['database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true]]);
        $default = config('database.default');
        config(['database.default' => 'backup_test']);

        try {
            $encryption = app(LocalDataEncryption::class);
            $backup = $encryption->backup('before-2.1.0-encryption');
            $this->assertFileExists($backup);
            $this->assertStringNotContainsString('Santos', file_get_contents($backup), 'The backup holds no readable personal data.');

            $restored = $backup.'.sqlite';
            $encryption->restoreBackup($backup, $restored);
            $names = (new \PDO('sqlite:'.$restored))->query('SELECT name FROM families ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
            $this->assertSame(['Santos', 'Reyes'], $names);
        } finally {
            config(['database.default' => $default]);
            DB::purge('backup_test');
            @unlink($backup ?? '');
            @unlink(($backup ?? '').'.sqlite');
            @unlink($file);
        }
    }

    public function test_the_key_lives_in_its_own_file_never_in_the_database(): void
    {
        $key = app(LocalDataKey::class);
        $key->encrypter();
        $this->assertFileExists($key->path());
        $this->assertSame('none', $key->protection(), 'Outside the desktop app the key is not protected by Windows.');
        $this->assertStringNotContainsString(base64_encode($key->encrypter()->getKey()), $this->rawDatabase());
        $this->assertNotSame(config('app.key'), 'base64:'.base64_encode($key->encrypter()->getKey()));
    }

    public function test_the_status_command_reports_nothing_in_plain_text(): void
    {
        $this->artisan('elikas:local-data', ['action' => 'status'])->expectsOutputToContain('Personal values still in plain text: 0')->assertSuccessful();
    }
    public function test_encryption_that_had_to_wait_is_done_at_the_apps_first_page(): void
    {
        \App\Models\LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official', 'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
        // Left in plain text by a migrate that could not open the key.
        DB::table('families')->insert(['barangay_id' => $this->barangay->id, 'evacuation_event_id' => $this->event->id, 'displacement_type' => 'inside_center',
            'name' => 'Rosalinda Magbanua', 'created_via_ec_board' => 1]);
        \App\Models\AppState::put(LocalDataEncryption::PENDING_FLAG, true);
        $encryption = app(LocalDataEncryption::class);
        $this->assertTrue($encryption->pending());
        $this->assertSame(1, $encryption->plainCount());

        $this->get(route('dashboard'))->assertOk();

        $this->assertFalse($encryption->pending());
        $this->assertSame(0, $encryption->plainCount());
        $this->assertStringNotContainsString('Rosalinda', $this->rawDatabase());
        $this->assertSame('Rosalinda Magbanua', Family::first()->name);
    }

    public function test_nothing_in_plain_text_means_no_backup_and_no_key_yet(): void
    {
        config(['elikas.local_data_key_path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'elikas-nokey-'.uniqid().'.key']);
        app(LocalDataKey::class)->reset();
        $this->assertSame([], app(LocalDataEncryption::class)->protectExistingOrDefer('test'));
        $this->assertFalse(app(LocalDataKey::class)->exists());
    }
}
