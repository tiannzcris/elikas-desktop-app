<?php

namespace Tests\Feature;

use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Empty pages say what is missing and offer the fix, never a blank area. */
class EmptyStatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_official_with_nothing_on_the_device_is_told_how_to_fill_it(): void
    {
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'barangay_name' => 'Bacong', 'api_token' => 't', 'logged_in_at' => now()]);

        foreach (['dashboard', 'ec-board.index'] as $route) {
            $this->get(route($route))->assertOk()->assertSee('data-empty', false)
                ->assertSee('No evacuation centers on this device yet.')->assertSee(route('reference-data.refresh'), false);
        }
        $this->get(route('sync.index'))->assertSee('Everything on this device has been sent.');
    }

    public function test_cswd_without_a_copy_gets_a_refresh_action(): void
    {
        LocalAuth::create(['remote_user_id' => 2, 'name' => 'C', 'email' => 'c@x', 'role' => 'cswd_personnel',
            'permissions' => ['operations.access', 'scope.all_barangays', 'page.monitoring', 'count_submissions.review'],
            'api_token' => 't', 'logged_in_at' => now()]);

        $this->get(route('monitoring.index'))->assertSee('Monitoring is not on this device yet.')->assertSee('Refresh now');
        $this->get(route('final-counts.index'))->assertSee('Final counts are not on this device yet.')->assertSee('Refresh now');
        $this->get(route('dashboard'))->assertSee('The event figures are not on this device yet.');
    }
}
