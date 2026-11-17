<?php

namespace Tests\Feature;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\ReturningFamilySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Data entry works from the keyboard: focus starts on the first field; the forms are real forms (Enter submits). */
class KeyboardEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_starts_on_the_email_field(): void
    {
        $this->get(route('login'))->assertSee('required autofocus autocomplete="username"', false);
    }

    public function test_entry_screens_start_on_their_first_field_and_submit_as_forms(): void
    {
        LocalAuth::create(['remote_user_id' => 1, 'name' => 'T', 'email' => 't@x', 'role' => 'barangay_official',
            'barangay_id' => 4, 'api_token' => 't', 'logged_in_at' => now()]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
        $event = EvacuationEvent::create(['remote_id' => 1, 'name' => 'Typhoon', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::create(['remote_id' => 7, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'active']);
        ReturningFamilySnapshot::create(['evacuation_event_id' => $event->id, 'fetched_at' => now(),
            'past_events' => [['id' => 2, 'name' => 'Past', 'family_count' => 0]], 'families_by_event' => ['2' => []]]);

        $board = $this->get(route('evacuation-centers.ec-board', $center))->getContent();
        $this->assertStringContainsString("if (e.key === 'Escape') { closeBoardModal(); return; }", $board);
        $this->assertMatchesRegularExpression('/<form method="POST" action="[^"]+\/evacuees"/', $board);
        $this->assertStringContainsString('type="submit"', $board);

        $this->get(route('returning-families.index', ['center' => $center, 'event' => $event->id]))
            ->assertSee('autofocus data-rf-search', false);
    }
}
