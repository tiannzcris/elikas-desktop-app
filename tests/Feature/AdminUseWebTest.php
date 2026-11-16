<?php

namespace Tests\Feature;

use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminUseWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_administrator_is_sent_to_the_web_and_nothing_is_kept(): void
    {
        Http::fake([
            '*/auth/login' => Http::response(['data' => ['token' => 'admin-token', 'user' => [
                'id' => 1, 'name' => 'Admin', 'email' => 'a@x', 'role' => 'administrator', 'status' => 'active',
                'barangay' => null, 'permissions' => ['users.manage', 'barangays.view'],
            ]]]),
            '*/auth/logout' => Http::response(['success' => true]),
        ]);

        $this->post(route('login.submit'), ['email' => 'a@x', 'password' => 'x'])->assertRedirect(route('use-web'));

        $this->assertNull(LocalAuth::current());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/auth/logout') && $r->hasHeader('Authorization', 'Bearer admin-token'));
        $this->get(route('use-web'))->assertOk()->assertSee('Use the web');
    }

    public function test_a_stored_session_that_lost_operations_access_is_signed_out(): void
    {
        Http::fake(['*/auth/logout' => Http::response(['success' => true])]);
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'A', 'email' => 'a@x', 'role' => 'administrator',
            'permissions' => ['users.manage'], 'api_token' => 't', 'logged_in_at' => now()]);

        $this->get(route('dashboard'))->assertRedirect(route('use-web'));
        $this->assertNull(LocalAuth::current());
    }

    public function test_pages_need_a_signed_in_user(): void
    {
        $this->get(route('ec-board.index'))->assertRedirect(route('login'));
    }
}
