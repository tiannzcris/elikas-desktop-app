<?php

namespace Tests\Feature;

use App\Models\AppState;
use App\Models\Barangay;
use App\Models\EcBoardEntry;
use App\Models\EvacuationCenter;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(int $id = 4): LocalAuth
    {
        return LocalAuth::create(['remote_user_id' => $id, 'name' => "User {$id}", 'email' => "u{$id}@x", 'role' => 'barangay_official',
            'barangay_id' => 13, 'api_token' => "token-{$id}", 'logged_in_at' => now()]);
    }

    private function entry(bool $sent, int $daysAgo = 0): EcBoardEntry
    {
        Barangay::firstOrCreate(['remote_id' => 13], ['name' => 'Binatagan']);
        $event = EvacuationEvent::firstOrCreate(['remote_id' => 1], ['name' => 'Typhoon', 'event_type' => 'typhoon', 'status' => 'active']);
        $center = EvacuationCenter::firstOrCreate(['remote_id' => 5], ['barangay_remote_id' => 13, 'name' => 'Hall', 'status' => 'active']);

        return EcBoardEntry::create(['evacuation_center_id' => $center->id, 'evacuation_event_id' => $event->id, 'sex' => 'female',
            'age_bracket' => 'adult', 'synced_at' => $sent ? now()->subDays($daysAgo) : null, 'remote_id' => $sent ? 99 : null]);
    }

    public function test_logout_revokes_the_token_keeps_waiting_and_recently_sent_records_and_drops_older_ones(): void
    {
        Http::fake(['*/auth/logout' => Http::response(['success' => true])]);
        $this->signIn();
        $waiting = $this->entry(false);
        $sent = $this->entry(true, 2);
        $old = $this->entry(true, 15);

        $this->post(route('logout'))->assertRedirect(route('login'));

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/auth/logout') && $r->hasHeader('Authorization', 'Bearer token-4'));
        $this->assertNull(LocalAuth::current());
        $this->assertDatabaseHas('ec_board_entries', ['id' => $waiting->id]);
        // Sync > Sent keeps the last 14 days.
        $this->assertDatabaseHas('ec_board_entries', ['id' => $sent->id]);
        $this->assertDatabaseMissing('ec_board_entries', ['id' => $old->id]);
        $this->assertSame(4, AppState::get('queue_owner')['id']);
    }

    public function test_logout_works_offline(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));
        $this->signIn();
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertNull(LocalAuth::current());
    }

    public function test_another_account_cannot_log_in_while_records_wait(): void
    {
        $this->entry(false);
        AppState::put('queue_owner', ['id' => 4, 'name' => 'User 4', 'email' => 'u4@x']);
        Http::fake([
            '*/auth/login' => Http::response(['data' => ['token' => 'other', 'user' => ['id' => 7, 'name' => 'Other', 'email' => 'o@x',
                'role' => 'barangay_official', 'barangay' => ['id' => 13, 'name' => 'B'], 'permissions' => ['operations.access']]]]),
            '*/auth/logout' => Http::response(['success' => true]),
        ]);

        $this->from(route('login'))->post(route('login.submit'), ['email' => 'o@x', 'password' => 'x'])
            ->assertRedirect(route('login'))->assertSessionHasErrors('login');
        $this->assertNull(LocalAuth::current());
    }
}
