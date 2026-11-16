<?php

namespace Tests\Feature;

use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutoSyncTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(string $role = 'barangay_official'): void
    {
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => $role,
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
    }

    public function test_the_probe_answers_reachable_and_refreshes_permissions(): void
    {
        $this->signIn();
        Http::fake(['*/auth/me' => Http::response(['data' => ['id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay' => ['id' => 4, 'name' => 'B'], 'permissions' => ['operations.access', 'families.re_enroll']]])]);

        $this->getJson(route('sync.probe'))->assertJson(['reachable' => true, 'auth' => true]);
        $this->assertSame(['operations.access', 'families.re_enroll'], LocalAuth::current()->permissions);
    }

    public function test_the_probe_says_offline(): void
    {
        $this->signIn();
        Http::fake(fn () => throw new ConnectionException('down'));
        $this->getJson(route('sync.probe'))->assertJson(['reachable' => false]);
    }

    public function test_the_probe_says_the_login_expired(): void
    {
        $this->signIn();
        Http::fake(['*/auth/me' => Http::response(['message' => 'Unauthenticated.'], 401)]);
        $this->getJson(route('sync.probe'))->assertJson(['reachable' => true, 'auth' => false]);
    }

    public function test_officials_get_the_sync_chip_and_cswd_does_not(): void
    {
        $this->signIn();
        $this->get(route('dashboard'))->assertSee('id="sync-chip"', false)->assertSee('data-auto-sync', false);

        LocalAuth::query()->delete();
        $this->signIn('cswd_personnel');
        $this->get(route('dashboard'))->assertDontSee('id="sync-chip"', false);
    }
}
