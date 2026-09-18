<?php

namespace Tests\Feature;

use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Covers the real incident this exists to prevent: a locally-run test
 * evacuee entry synced straight into the live production database
 * because there was no dev-safe way to point this app at a local server,
 * and no visible indicator when a session was accidentally still pointed
 * at production during testing (or vice versa). See config/elikas.php's
 * own docblock for the full story.
 */
class ApiTargetSwitchingTest extends TestCase
{
    use RefreshDatabase;

    private function overrideFile(): string
    {
        return storage_path('app/dev-api-target.txt');
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Never trust a leftover override file from outside this test --
        // every test here starts from "no override" (production) state.
        if (file_exists($this->overrideFile())) {
            unlink($this->overrideFile());
        }
    }

    protected function tearDown(): void
    {
        // This writes to the REAL project storage directory (phpunit.xml
        // doesn't sandbox storage_path()), so leaving a stray override
        // file behind would silently point every future artisan/test run
        // at a fake target -- always clean up regardless of pass/fail.
        if (file_exists($this->overrideFile())) {
            unlink($this->overrideFile());
        }
        parent::tearDown();
    }

    public function test_with_no_override_file_the_central_api_url_resolves_to_the_configured_production_url(): void
    {
        $this->assertFalse(file_exists($this->overrideFile()));
        $this->assertSame(config('elikas.production_api_url'), config('elikas.central_api_url'));
    }

    public function test_the_api_target_command_with_no_argument_reports_production_when_no_override_is_active(): void
    {
        $this->artisan('elikas:api-target')
            ->expectsOutputToContain('(production -- no override active)')
            ->assertExitCode(0);
    }

    public function test_setting_local_target_writes_the_override_file_with_the_expected_default_url(): void
    {
        Artisan::call('elikas:api-target', ['target' => 'local']);

        $this->assertFileExists($this->overrideFile());
        $this->assertSame('http://127.0.0.1:8000/api/v1', trim(file_get_contents($this->overrideFile())));
    }

    public function test_setting_a_custom_url_target_writes_that_exact_url(): void
    {
        Artisan::call('elikas:api-target', ['target' => 'https://staging.example.com/api/v1']);

        $this->assertSame('https://staging.example.com/api/v1', trim(file_get_contents($this->overrideFile())));
    }

    public function test_an_invalid_target_is_rejected_and_writes_nothing(): void
    {
        $exitCode = Artisan::call('elikas:api-target', ['target' => 'not-a-url']);

        $this->assertSame(1, $exitCode);
        $this->assertFileDoesNotExist($this->overrideFile());
    }

    public function test_switching_to_production_removes_an_existing_override_file(): void
    {
        file_put_contents($this->overrideFile(), 'http://127.0.0.1:8000/api/v1');

        Artisan::call('elikas:api-target', ['target' => 'production']);

        $this->assertFileDoesNotExist($this->overrideFile());
    }

    public function test_an_override_file_actually_changes_what_config_elikas_central_api_url_resolves_to(): void
    {
        file_put_contents($this->overrideFile(), 'http://127.0.0.1:9999/api/v1');

        // config/elikas.php is a plain array built once at boot from a
        // closure -- re-require it directly (bypassing the cached config
        // repository) to prove the override file is actually consulted,
        // the same way a fresh page load in dev mode would pick it up.
        $resolved = (require config_path('elikas.php'))['central_api_url'];

        $this->assertSame('http://127.0.0.1:9999/api/v1', $resolved);
        $this->assertNotSame(config('elikas.production_api_url'), $resolved);
    }

    public function test_dashboard_shows_the_warning_banner_when_an_override_is_active(): void
    {
        file_put_contents($this->overrideFile(), 'http://127.0.0.1:8000/api/v1');
        // config() is resolved once per process/test in this suite's own
        // bootstrap -- force config/elikas.php to be re-evaluated so the
        // page render below reflects the override file written above,
        // matching how a real fresh request (a new PHP process per
        // request, in this app's own dev-server model) would.
        config(['elikas.central_api_url' => (require config_path('elikas.php'))['central_api_url']]);

        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official',
            'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);

        $page = $this->get(route('dashboard'));

        $page->assertSee('DEV MODE -- NOT PRODUCTION');
        $page->assertSee('http://127.0.0.1:8000/api/v1', false);
    }

    public function test_dashboard_shows_no_banner_when_targeting_production(): void
    {
        config(['elikas.central_api_url' => config('elikas.production_api_url')]);

        LocalAuth::create([
            'remote_user_id' => 1, 'name' => 'Tester', 'email' => 't@example.com', 'role' => 'barangay_official',
            'api_token' => 'fake-token', 'logged_in_at' => now(),
        ]);

        $page = $this->get(route('dashboard'));

        $page->assertDontSee('DEV MODE -- NOT PRODUCTION');
    }

    public function test_login_page_also_shows_the_banner_when_an_override_is_active(): void
    {
        config(['elikas.central_api_url' => 'http://127.0.0.1:8000/api/v1']);

        $page = $this->get(route('login'));

        $page->assertSee('DEV MODE -- NOT PRODUCTION');
    }

    public function test_the_override_file_path_is_excluded_from_packaged_builds(): void
    {
        $this->assertContains('storage/app/dev-api-target.txt', config('nativephp.cleanup_exclude_files'));
    }
}
