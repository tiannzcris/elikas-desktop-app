<?php

namespace Tests\Feature;

use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $overrides = []): array
    {
        return array_merge([
            'id' => 4, 'name' => 'Ana Official', 'email' => 'ana@example.com', 'status' => 'active',
            'role' => 'barangay_official', 'role_display_name' => 'Barangay Official',
            'barangay' => ['id' => 13, 'name' => 'Binatagan'],
            'permissions' => ['operations.access', 'puroks.view', 'families.re_enroll'],
        ], $overrides);
    }

    public function test_login_stores_the_permissions_and_role_label(): void
    {
        Http::fake([
            '*/auth/login' => Http::response(['data' => ['user' => $this->user(), 'token' => 't1']]),
            '*/barangays' => Http::response(['data' => []]),
            '*/evacuation-events' => Http::response(['data' => []]),
            '*/evacuation-centers?detail=1' => Http::response(['data' => []]),
        ]);

        $this->post(route('login.submit'), ['email' => 'ana@example.com', 'password' => 'x'])->assertRedirect(route('dashboard'));

        $auth = LocalAuth::current();
        $this->assertSame(['operations.access', 'puroks.view', 'families.re_enroll'], $auth->permissions);
        $this->assertTrue($auth->can('families.re_enroll'));
        $this->assertFalse($auth->can('scope.all_barangays'));
        $this->assertTrue($auth->isBarangayOfficial());
        $this->assertSame('Barangay Official', $auth->roleLabel());
        $this->assertSame('active', $auth->status);
    }

    public function test_refresh_updates_the_identity_from_auth_me(): void
    {
        LocalAuth::create(LocalAuth::attributesFromUser($this->user()) + ['api_token' => 't1', 'logged_in_at' => now()]);
        Http::fake([
            '*/auth/me' => Http::response(['data' => $this->user(['barangay' => ['id' => 14, 'name' => 'Bagumbayan'], 'permissions' => ['operations.access']])]),
            '*/barangays' => Http::response(['data' => []]),
            '*/evacuation-events' => Http::response(['data' => []]),
            '*/evacuation-centers?detail=1' => Http::response(['data' => []]),
        ]);

        $this->post(route('reference-data.refresh'));

        $auth = LocalAuth::current();
        $this->assertSame(14, (int) $auth->barangay_id);
        $this->assertSame(['operations.access'], $auth->permissions);
        $this->assertNotNull($auth->identity_checked_at);
    }

    public function test_a_row_without_stored_permissions_falls_back_to_its_role(): void
    {
        $auth = LocalAuth::create(['remote_user_id' => 1, 'name' => 'C', 'email' => 'c@x', 'role' => 'cswd_personnel', 'api_token' => 't', 'logged_in_at' => now()]);
        $this->assertTrue($auth->isCityWide());
        $this->assertFalse($auth->isBarangayOfficial());
    }
}
