<?php

namespace Tests\Feature;

use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(string $role, array $permissions): void
    {
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'Staff', 'email' => 's@x', 'role' => $role, 'permissions' => $permissions,
            'barangay_id' => $role === 'barangay_official' ? 13 : null, 'api_token' => 't', 'logged_in_at' => now()]);
    }

    public function test_an_official_sees_operations(): void
    {
        $this->signIn('barangay_official', ['operations.access']);
        $this->get(route('dashboard'))->assertOk()->assertSee('data-nav-group="operations"', false);
        $this->get(route('ec-board.index'))->assertOk();
    }

    public function test_cswd_does_not_get_registration(): void
    {
        $this->signIn('cswd_personnel', ['operations.access', 'scope.all_barangays']);
        $this->get(route('dashboard'))->assertOk()->assertDontSee('data-nav-group="operations"', false);
        $this->get(route('ec-board.index'))->assertRedirect(route('dashboard'));
        $this->post(route('families.sync'))->assertRedirect(route('dashboard'));
    }
}
