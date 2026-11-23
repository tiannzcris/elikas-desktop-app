<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\ServerSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_official_sees_what_waits_and_their_centers_first(): void
    {
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'barangay_name' => 'Bacong', 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon Drill', 'event_type' => 'typhoon', 'status' => 'active', 'is_demo' => true]);
        $center = EvacuationCenter::create(['remote_id' => 7, 'barangay_remote_id' => 4, 'name' => 'Bacong Gym', 'status' => 'active']);
        EcBoardEntry::create(['evacuation_center_id' => $center->id, 'evacuation_event_id' => $event->id, 'sex' => 'male', 'age_bracket' => 'adult']);

        $this->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['Current event', 'Typhoon Drill', 'Waiting to send', '1', 'Your centers', 'Bacong Gym', '1 person waiting to sync'])
            ->assertDontSee('data-demo-badge', false)->assertDontSee('DEMO')
            ->assertDontSee('Welcome back');
    }

    public function test_cswd_sees_the_event_figures_and_what_waits_for_validation(): void
    {
        LocalAuth::create(['remote_user_id' => 2, 'name' => 'C', 'email' => 'c@x', 'role' => 'cswd_personnel',
            'permissions' => ['operations.access', 'scope.all_barangays', 'page.monitoring', 'count_submissions.review'],
            'api_token' => 't', 'logged_in_at' => now()]);
        ServerSnapshot::keep('monitoring.overview', ['evacuation_event' => ['id' => 9, 'name' => 'Typhoon Amang', 'is_demo' => false],
            'generated_at' => '2026-11-16T05:00:00+00:00', 'data' => ['figures' => ['persons' => 1234, 'families' => 300, 'centers_in_use' => 7]]]);
        ServerSnapshot::keep('final_counts', ['barangays' => [
            ['barangay' => ['name' => 'A'], 'current_submission' => ['status' => 'pending']],
            ['barangay' => ['name' => 'B'], 'current_submission' => null],
        ]]);

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Typhoon Amang')->assertSee('1,234')
            ->assertSeeInOrder(['Final counts to validate', '1', '1 not submitted'])
            ->assertSee('Read-only');
    }
}
