<?php

namespace Tests\Feature;

use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoAndAboutTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_logo_is_on_the_login_screen(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('images/elikas-logo-mark.png', false);
    }

    public function test_the_sidebar_and_about_page_show_the_logo_and_version(): void
    {
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'Maria Santos', 'email' => 'm@x', 'role' => 'barangay_official',
            'role_display_name' => 'Barangay Official', 'barangay_id' => 4, 'barangay_name' => 'Bacong', 'api_token' => 't', 'logged_in_at' => now()]);

        $this->get(route('dashboard'))->assertSee('images/elikas-logo-mark.png', false)->assertSee(route('about'), false);
        $this->get(route('about'))->assertOk()
            ->assertSee('data-about-logo', false)
            ->assertSee(config('nativephp.version'))
            ->assertSee('Maria Santos · Barangay Official');
    }
}
