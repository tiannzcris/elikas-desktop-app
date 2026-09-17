<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers the real incident this feature exists for: a rebuilt app shipped
 * with new migration files, but the persistent on-device database (which
 * survives across installs, unlike the packaged app code) never had them
 * applied -- every page touching a new table 500'd with "no such table".
 *
 * Deliberately does NOT use RefreshDatabase -- that trait auto-migrates
 * everything before each test, which is exactly the state these tests
 * need to NOT start in. Each test method already gets a fresh in-memory
 * SQLite database (a new Application container per test method, per
 * Laravel's own TestCase::setUp()), so migration state here is built up
 * by hand via Artisan::call() instead.
 */
class PendingMigrationUpdateTest extends TestCase
{
    private string $heldBackMigration = '2026_02_01_000010_create_ec_board_entries_table.php';

    private string $heldBackPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Simulates a device on an older app version: every migration
        // EXCEPT the most recent one has already run. Moving the actual
        // file out of database/migrations (rather than e.g. faking a row
        // in the migrations table) means a later `migrate` genuinely has
        // a real table left to create -- matching today's real incident,
        // not just a bookkeeping mismatch.
        $this->heldBackPath = sys_get_temp_dir().'/'.$this->heldBackMigration;
        rename(database_path('migrations/'.$this->heldBackMigration), $this->heldBackPath);

        Artisan::call('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        // Guarded: several tests already move it back themselves as part
        // of what they're testing, so it may no longer be at the held-
        // back path by the time tearDown runs.
        if (file_exists($this->heldBackPath)) {
            rename($this->heldBackPath, database_path('migrations/'.$this->heldBackMigration));
        }

        parent::tearDown();
    }

    public function test_a_device_with_a_pending_migration_is_redirected_to_the_update_screen_instead_of_silently_migrating(): void
    {
        // The new app version's migration file "arrives" -- mirrors
        // installing a newer build on top of an older, un-migrated database.
        rename($this->heldBackPath, database_path('migrations/'.$this->heldBackMigration));

        $countBefore = DB::table('migrations')->count();

        $response = $this->get('/dashboard');

        $response->assertRedirect(route('system.update-required'));

        // Nothing ran silently just from visiting a page -- this is the
        // entire point: the block is a click-to-confirm gate, never a
        // background migration.
        $this->assertSame($countBefore, DB::table('migrations')->count());
        $this->assertFalse(Schema::hasTable('ec_board_entries'));
    }

    public function test_clicking_update_now_runs_the_pending_migration_and_shows_success(): void
    {
        rename($this->heldBackPath, database_path('migrations/'.$this->heldBackMigration));

        $page = $this->get(route('system.update-required'));
        $page->assertOk();
        $page->assertSee('Update Now');

        $response = $this->post(route('system.update-required.run'));

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('status', 'App updated successfully.');
        $this->assertTrue(Schema::hasTable('ec_board_entries'));

        // The app now behaves completely normally -- no more update
        // prompt, even for this unauthenticated request (it just falls
        // through to the ordinary "please log in" redirect).
        $after = $this->get('/dashboard');
        $after->assertRedirect(route('login'));
    }

    public function test_an_up_to_date_app_launches_normally_with_no_update_prompt(): void
    {
        rename($this->heldBackPath, database_path('migrations/'.$this->heldBackMigration));
        Artisan::call('migrate', ['--force' => true]);

        $response = $this->get('/dashboard');

        $response->assertRedirect(route('login'));
    }

    public function test_a_failed_update_shows_a_clear_error_and_leaves_pending_state_intact(): void
    {
        rename($this->heldBackPath, database_path('migrations/'.$this->heldBackMigration));

        // Sabotage: pre-create the table the pending migration is about
        // to create, so its own Schema::create() call fails -- simulates
        // a real migration failure (e.g. a conflicting/corrupt schema)
        // rather than the happy path.
        Schema::create('ec_board_entries', function ($table) {
            $table->id();
        });

        $response = $this->post(route('system.update-required.run'));

        $response->assertRedirect();
        $response->assertSessionHasErrors('update');

        // Still blocked -- a failed update must not silently wave the
        // user through into an app running on a half-updated schema.
        $after = $this->get('/dashboard');
        $after->assertRedirect(route('system.update-required'));
    }
}
