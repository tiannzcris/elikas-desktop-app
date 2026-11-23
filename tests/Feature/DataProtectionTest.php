<?php

namespace Tests\Feature;

use App\Models\AppState;
use App\Models\Barangay;
use App\Models\CenterFamilySnapshot;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\PendingCheckOut;
use App\Models\ServerSnapshot;
use App\Security\IdleSignOut;
use App\Services\LocalData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 2.1.0 part 8: signing out after inactivity, and closed events' data leaving the computer. */
class DataProtectionTest extends TestCase
{
    use RefreshDatabase;

    private LocalAuth $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = LocalAuth::create(['remote_user_id' => 1, 'name' => 'Maria Santos', 'email' => 'brgy.bacong', 'role' => 'barangay_official',
            'barangay_id' => 4, 'barangay_name' => 'Bacong', 'api_token' => 't', 'logged_in_at' => now(), 'password_hash' => Hash::make('right-password')]);
        Barangay::create(['remote_id' => 4, 'name' => 'Bacong']);
    }

    private function idleFor(int $minutes): void
    {
        AppState::put('last_activity_at', now()->subMinutes($minutes)->toIso8601String());
    }

    public function test_after_15_minutes_without_activity_every_page_asks_to_sign_in_again(): void
    {
        $this->idleFor(14);
        $this->get(route('dashboard'))->assertOk();

        $this->idleFor(16);
        $this->get(route('ec-board.index'))->assertRedirect(route('unlock'));
        $this->assertNotNull($this->auth->fresh()->locked_at);
        $this->get(route('unlock'))->assertOk()->assertSee('Signed out after 15 minutes without activity')->assertSee('Maria Santos')->assertDontSee('Bacong Gym');
        // Background requests are refused too, and never count as activity.
        $this->getJson(route('notifications.feed'))->assertStatus(423);
        $this->postJson(route('sync.step'))->assertStatus(423);
    }

    public function test_background_requests_do_not_keep_the_session_alive(): void
    {
        $this->idleFor(10);
        Http::fake(fn () => throw new ConnectionException('down'));
        $this->getJson(route('notifications.feed'));
        $this->assertTrue(now()->subMinutes(9)->gt(\Illuminate\Support\Carbon::parse(AppState::get('last_activity_at'))));
    }

    public function test_signing_in_again_works_offline_and_keeps_the_queue(): void
    {
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'active']);
        $event = EvacuationEvent::create(['remote_id' => 9, 'name' => 'E', 'event_type' => 'typhoon', 'status' => 'active']);
        PendingCheckOut::create(['evacuation_center_id' => $center->id, 'evacuation_event_id' => $event->id, 'family_remote_id' => 5, 'family_label' => 'Reyes', 'status' => 'returned_home']);
        $this->post(route('lock'))->assertRedirect(route('unlock'));
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->get(route('unlock'))->assertSee('1 record waits on this computer');
        $this->post(route('unlock.submit'), ['password' => 'wrong'])->assertSessionHasErrors(['password' => 'The password is incorrect.']);
        $this->post(route('unlock.submit'), ['password' => 'right-password'])->assertRedirect(route('dashboard'));

        $this->assertNull($this->auth->fresh()->locked_at);
        $this->assertSame(1, app(LocalData::class)->pendingCount());
        Http::assertNothingSent();
    }

    public function test_five_wrong_passwords_stop_the_offline_check_for_15_minutes(): void
    {
        $this->post(route('lock'));
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('unlock.submit'), ['password' => 'wrong']);
        }
        $this->post(route('unlock.submit'), ['password' => 'right-password'])->assertSessionHasErrors('password');
        $this->assertNotNull($this->auth->fresh()->locked_at);

        $this->travel(16)->minutes();
        $this->post(route('unlock.submit'), ['password' => 'right-password'])->assertRedirect(route('dashboard'));
    }

    public function test_an_account_signed_in_before_2_1_needs_the_internet_once(): void
    {
        $this->auth->update(['password_hash' => null]);
        $this->post(route('lock'));

        $online = false;
        Http::fake(function ($request) use (&$online) {
            if (! $online) {
                throw new ConnectionException('down');
            }

            return str_contains($request->url(), 'auth/login')
                ? Http::response(['data' => ['token' => 'new', 'user' => ['id' => 1, 'name' => 'Maria Santos', 'email' => 'brgy.bacong', 'role' => 'barangay_official']]])
                : Http::response([]);
        });
        $this->post(route('unlock.submit'), ['password' => 'p'])->assertSessionHasErrors('password');

        $online = true;
        $this->post(route('unlock.submit'), ['password' => 'p'])->assertRedirect(route('dashboard'));
        $this->assertSame('new', $this->auth->fresh()->api_token);
        $this->assertTrue(Hash::check('p', $this->auth->fresh()->password_hash));
    }

    public function test_the_timeout_can_be_changed(): void
    {
        $this->post(route('data-security.timeout'), ['minutes' => 30])->assertSessionHas('status', 'Signing out after 30 minutes without activity.');
        $this->idleFor(20);
        $this->get(route('dashboard'))->assertOk()->assertSee('<meta name="idle-timeout" content="1800"', false);
        $this->post(route('data-security.timeout'), ['minutes' => 7])->assertSessionHasErrors('minutes');
    }

    public function test_a_closed_events_data_leaves_once_everything_is_sent(): void
    {
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'active']);
        $closed = EvacuationEvent::create(['remote_id' => 60, 'name' => 'Typhoon Opong', 'event_type' => 'typhoon', 'status' => 'closed']);
        $open = EvacuationEvent::create(['remote_id' => 79, 'name' => 'Typhoon Drill', 'event_type' => 'typhoon', 'status' => 'active']);
        foreach ([$closed, $open] as $event) {
            CenterFamilySnapshot::create(['evacuation_center_id' => $center->id, 'evacuation_event_id' => $event->id, 'families' => [['id' => 1, 'name' => 'Reyes']], 'fetched_at' => now()]);
            ServerSnapshot::keep('families_to_evacuate.'.$event->remote_id, ['families' => []]);
        }
        $waiting = EcBoardEntry::create(['evacuation_center_id' => $center->id, 'evacuation_event_id' => $closed->id, 'sex' => 'male', 'age_bracket' => 'adult']);

        // A record still waits: nothing of that event leaves.
        $this->assertSame([], app(LocalData::class)->pruneClosedEvents());
        $this->get(route('data-security.index'))->assertSee('Typhoon Opong')->assertSee('1 record still waiting: kept');

        $waiting->update(['synced_at' => now()]);
        $this->post(route('data-security.prune'))->assertSessionHas('status', 'Removed from this computer: Typhoon Opong. The server keeps everything.');

        $this->assertSame(0, CenterFamilySnapshot::where('evacuation_event_id', $closed->id)->count());
        $this->assertSame(0, EcBoardEntry::count());
        $this->assertNull(ServerSnapshot::named('families_to_evacuate.60'));
        // The open event keeps everything, and the closed event can still be chosen.
        $this->assertSame(1, CenterFamilySnapshot::where('evacuation_event_id', $open->id)->count());
        $this->assertNotNull(ServerSnapshot::named('families_to_evacuate.79'));
        $this->assertNotNull($closed->fresh());
    }

    public function test_the_page_says_how_the_data_is_protected(): void
    {
        $this->get(route('data-security.index'))->assertOk()
            ->assertSee('Sign out after inactivity')->assertSee('15 minutes (default)')
            ->assertSee('Encrypted on this computer');
    }
}
