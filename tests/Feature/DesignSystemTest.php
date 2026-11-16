<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Web design system rules the desktop follows (docs/design-system.md on the backend). */
class DesignSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_destructive_actions_hide_in_a_menu_and_ask_first(): void
    {
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 7, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'active']);
        EcBoardEntry::create(['evacuation_center_id' => $center->id, 'evacuation_event_id' => $event->id, 'sex' => 'male', 'age_bracket' => 'adult']);

        $page = $this->get(route('evacuation-centers.ec-board', ['center' => $center, 'event' => $event->id]))->assertOk();
        $page->assertSee('data-menu-toggle', false)
            ->assertSee('data-confirm-tone="danger"', false)
            ->assertSee('Delete entry')
            ->assertDontSee('confirm(\'', false);
        // One primary button in the page header.
        $this->assertSame(1, substr_count(explode('<section', $page->getContent())[0], 'btn btn-primary'));
    }
}
