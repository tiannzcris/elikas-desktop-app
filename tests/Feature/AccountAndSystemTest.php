<?php

namespace Tests\Feature;

use App\Models\AppState;
use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\PendingCheckOut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 2.1.0 part 10: username sign-in, temporary passwords, locked accounts, maintenance, device headers, notices, Report a problem. */
class AccountAndSystemTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $extra = []): array
    {
        return ['id' => 1, 'name' => 'Tuburan Barangay Official', 'email' => 'brgy.tuburan@e-likasligao.online', 'username' => 'brgy.tuburan',
            'role' => 'barangay_official', 'permissions' => ['operations.access', 'evacuees.manage', 'support.report'],
            'barangay' => ['id' => 4, 'name' => 'Tuburan']] + $extra;
    }

    private function signIn(array $extra = []): LocalAuth
    {
        return LocalAuth::create(LocalAuth::attributesFromUser($this->user($extra)) + ['api_token' => 't', 'logged_in_at' => now()]);
    }

    public function test_signing_in_with_a_username_works_and_is_not_checked_as_an_email(): void
    {
        Http::fake(['*/auth/login' => Http::response(['data' => ['token' => 'n', 'user' => $this->user()]]), '*' => Http::response(['data' => []])]);

        $this->get(route('login'))->assertSee('Email or username')->assertSee('type="text" name="email"', false);
        $this->post(route('login.submit'), ['email' => ' brgy.tuburan ', 'password' => 'secret-pass'])->assertRedirect(route('dashboard'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/auth/login') && $r['email'] === 'brgy.tuburan');
        $this->assertSame('brgy.tuburan', LocalAuth::current()->username);
    }

    public function test_a_temporary_password_must_be_changed_before_anything_else(): void
    {
        Http::fake([
            '*/auth/login' => Http::response(['data' => ['token' => 'n', 'user' => $this->user(['must_change_password' => true])]]),
            '*/auth/password' => Http::response(['data' => ['user' => $this->user(['must_change_password' => false])]]),
            '*' => Http::response(['data' => []], 403),
        ]);

        $this->post(route('login.submit'), ['email' => 'brgy.tuburan', 'password' => 'tuburan-q7K-2mPd-X4a'])->assertRedirect(route('password.change'));
        $this->get(route('ec-board.index'))->assertRedirect(route('password.change'));
        $this->get(route('password.change'))->assertSee('Choose your own password')->assertSee('data-password-change-required', false);

        $this->post(route('password.update'), ['current_password' => 'tuburan-q7K-2mPd-X4a', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post(route('password.update'), ['current_password' => 'tuburan-q7K-2mPd-X4a', 'password' => 'a-long-new-pass', 'password_confirmation' => 'a-long-new-pass'])
            ->assertRedirect(route('dashboard'));

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/auth/password') && $r['current_password'] === 'tuburan-q7K-2mPd-X4a' && $r['password'] === 'a-long-new-pass');
        $auth = LocalAuth::current();
        $this->assertFalse($auth->must_change_password);
        $this->assertTrue(Hash::check('a-long-new-pass', $auth->password_hash), 'Signing in again offline uses the new password.');
    }

    public function test_a_403_password_change_required_sends_the_user_to_change_it(): void
    {
        $this->signIn();
        Http::fake(['*/notifications' => Http::response(['message' => 'Change your temporary password first.', 'errors' => ['code' => 'password_change_required']], 403)]);

        $this->getJson(route('notifications.feed'));
        $this->assertTrue(LocalAuth::current()->must_change_password);
        $this->get(route('dashboard'))->assertRedirect(route('password.change'));
    }

    public function test_a_wrong_current_password_says_so(): void
    {
        $this->signIn();
        Http::fake(['*/auth/password' => Http::response(['message' => 'The current password is incorrect.', 'errors' => ['current_password' => ['The current password is incorrect.']]], 422)]);
        $this->post(route('password.update'), ['current_password' => 'nope', 'password' => 'a-long-new-pass', 'password_confirmation' => 'a-long-new-pass'])
            ->assertSessionHasErrors(['current_password' => 'The current password is incorrect.']);
    }

    public function test_a_locked_account_and_maintenance_show_the_servers_message(): void
    {
        Http::fake(['*/auth/login' => Http::sequence()
            ->push(['message' => 'Too many failed sign-ins. Try again at 10:42 PM.'], 423)
            ->push(['message' => 'Updating until 10:30 PM.', 'errors' => ['code' => 'maintenance']], 503)]);

        $this->from(route('login'))->post(route('login.submit'), ['email' => 'brgy.tuburan', 'password' => 'x'])
            ->assertSessionHasErrors(['locked' => 'Too many failed sign-ins. Try again at 10:42 PM.']);
        $this->get(route('login'))->assertSee('Account locked')->assertSee('data-account-locked', false);

        $this->from(route('login'))->post(route('login.submit'), ['email' => 'brgy.tuburan', 'password' => 'x'])
            ->assertSessionHasErrors(['maintenance' => 'Updating until 10:30 PM.']);
        $this->get(route('login'))->assertSee('The server is under maintenance');
    }

    public function test_maintenance_while_signed_in_shows_a_banner_and_keeps_the_work(): void
    {
        $this->signIn();
        Http::fake(['*/notifications' => Http::response(['message' => 'Updating until 10:30 PM.', 'errors' => ['code' => 'maintenance']], 503)]);
        $this->getJson(route('notifications.feed'));

        $this->get(route('dashboard'))->assertSee('data-maintenance-banner', false)->assertSee('Updating until 10:30 PM.');
    }

    public function test_every_request_carries_the_device_and_sync_headers(): void
    {
        $this->signIn();
        Barangay::create(['remote_id' => 4, 'name' => 'Tuburan']);
        $center = EvacuationCenter::create(['remote_id' => 1, 'barangay_remote_id' => 4, 'name' => 'Gym', 'status' => 'active']);
        $event = EvacuationEvent::create(['remote_id' => 9, 'name' => 'E', 'event_type' => 'typhoon', 'status' => 'active']);
        PendingCheckOut::create(['evacuation_center_id' => $center->id, 'evacuation_event_id' => $event->id, 'family_remote_id' => 5, 'family_label' => 'A', 'status' => 'returned_home']);
        PendingCheckOut::create(['evacuation_center_id' => $center->id, 'evacuation_event_id' => $event->id, 'family_remote_id' => 6, 'family_label' => 'B', 'status' => 'returned_home', 'sync_state' => 'failed']);
        AppState::put('last_sync_at', '2026-11-23T05:00:00+00:00');
        Http::fake(['*/notifications' => Http::response(['data' => ['unread' => 0, 'notifications' => []]])]);

        $this->getJson(route('notifications.feed'));

        $deviceId = AppState::get('device_id');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._:-]{4,100}$/', $deviceId);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/notifications')
            && $r->header('X-Device-Id') === [$deviceId]
            && $r->header('X-App-Version') === [config('nativephp.version')]
            && $r->header('X-App-Platform') === ['desktop']
            && $r->header('X-Sync-Pending') === ['1']
            && $r->header('X-Sync-Failed') === ['1']
            && $r->header('X-Last-Sync') === ['2026-11-23T05:00:00+00:00']);
        // The same id every time.
        $this->getJson(route('notifications.feed'));
        $this->assertSame($deviceId, AppState::get('device_id'));
    }

    public function test_system_notices_show_as_a_banner(): void
    {
        $this->signIn();
        AppState::put('system_notices', [
            ['id' => 1, 'title' => 'New version 2.1.0', 'message' => 'Download it from the website.', 'ends_at' => now()->addDay()->toIso8601String()],
            ['id' => 2, 'title' => 'Old notice', 'message' => 'Gone', 'ends_at' => now()->subHour()->toIso8601String()],
        ]);

        $this->get(route('dashboard'))->assertSee('data-system-notice="1"', false)->assertSee('New version 2.1.0')->assertDontSee('Old notice');

        // Replace the base test case's "no notices" answer.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['*/system-notices' => Http::response(['data' => [['id' => 3, 'title' => 'Maintenance at 10 PM', 'message' => 'About 30 minutes.']]])]);
        $this->get(route('system-notices.feed'))->assertSee('Maintenance at 10 PM')->assertDontSee('New version 2.1.0');
    }

    public function test_report_a_problem_with_a_screenshot(): void
    {
        $this->signIn();
        Http::fake([
            '*/support-requests' => Http::response(['data' => ['id' => 12, 'status' => 'new']], 201),
            '*/support-requests/mine' => Http::response(['data' => [['id' => 12, 'what_happened' => 'The EC Board shows no families after sync.', 'page' => '/ec-board',
                'status' => 'in_progress', 'status_label' => 'In progress', 'reply' => 'We are looking into it.', 'created_at' => now()->toIso8601String()]]]),
        ]);

        $this->get(route('support.index', ['from' => 'ec-board']))->assertSee("Do not include evacuees' names or details in the screenshot.", false)->assertSee('value="/ec-board"', false);
        $this->post(route('support.store'), ['what_happened' => 'short'])->assertSessionHasErrors('what_happened');
        $this->post(route('support.store'), [
            'what_happened' => 'The EC Board shows no families after sync.', 'page' => '/ec-board',
            'screenshot' => UploadedFile::fake()->image('board.png', 400, 300),
        ])->assertRedirect(route('support.index'));

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/support-requests') && $r->isMultipart()
            && collect($r->data())->firstWhere('name', 'what_happened')['contents'] === 'The EC Board shows no families after sync.'
            && collect($r->data())->firstWhere('name', 'source')['contents'] === 'desktop'
            && collect($r->data())->firstWhere('name', 'screenshot') !== null);
        $this->get(route('support.index'))->assertSee('In progress')->assertSee('We are looking into it.');
    }

    public function test_a_screenshot_must_be_a_picture_under_5_mb(): void
    {
        $this->signIn();
        $this->post(route('support.store'), ['what_happened' => 'Something long enough.', 'screenshot' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors(['screenshot' => 'The screenshot must be a PNG, JPG or WEBP picture.']);
        $this->post(route('support.store'), ['what_happened' => 'Something long enough.', 'screenshot' => UploadedFile::fake()->image('big.png')->size(6000)])
            ->assertSessionHasErrors(['screenshot' => 'The screenshot can be at most 5 MB.']);
        Http::assertNothingSent();
    }
}
