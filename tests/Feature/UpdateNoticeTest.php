<?php

namespace Tests\Feature;

use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** "A new version is available", from downloads/desktop-version.json on the hosting. */
class UpdateNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nativephp.version' => '2.0.2']);
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
    }

    public function test_a_newer_published_version_is_offered(): void
    {
        Http::fake(['*/downloads/desktop-version.json' => Http::response(['latest' => '2.0.3', 'download_url' => 'https://e-likasligao.online/downloads/E-LIKAS-Setup.exe'])]);

        $this->getJson(route('app-version.check'))->assertJson(['current' => '2.0.2', 'latest' => '2.0.3', 'update' => true]);
        $this->get(route('dashboard'))->assertSee('data-update-banner', false)->assertSee('A new version of E-LIKAS is available');
        $this->get(route('app-version.download'))->assertRedirect('https://e-likasligao.online/downloads/E-LIKAS-Setup.exe');
    }

    public function test_the_same_or_an_older_version_is_not_an_update(): void
    {
        Http::fake(['*/downloads/desktop-version.json' => Http::response(['latest' => '2.0.2'])]);
        $this->getJson(route('app-version.check'))->assertJson(['update' => false]);
    }

    public function test_a_newer_minor_version_is_an_update(): void
    {
        Http::fake(['*/downloads/desktop-version.json' => Http::response(['latest' => '2.1.0'])]);
        $this->getJson(route('app-version.check'))->assertJson(['update' => true]);
    }

    public function test_offline_with_no_check_yet_there_is_no_update(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));
        $this->getJson(route('app-version.check'))->assertJson(['latest' => null, 'update' => false]);
    }

    public function test_only_an_https_download_link_is_used(): void
    {
        Http::fake(['*/downloads/desktop-version.json' => Http::response(['latest' => '2.0.3', 'download_url' => 'javascript:alert(1)'])]);
        $this->getJson(route('app-version.check'));
        $this->get(route('app-version.download'))->assertRedirect(config('elikas.download_page_url'));
    }
}
