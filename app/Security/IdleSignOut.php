<?php

namespace App\Security;

use App\Models\AppState;
use App\Models\LocalAuth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Signing out after inactivity (default 15 minutes, set on the Data and
 * security page). The person is signed out of the screen: records waiting
 * to be sent stay, and the account's token is kept so they can still be
 * sent, but nothing can be seen or done until they sign in again.
 *
 * Signing in again works offline: the password is checked against a bcrypt
 * hash of the password last used to sign in online (encrypted at rest with
 * the rest of local_auth). After 5 wrong passwords in a row the offline
 * check stops for 15 minutes (the server's own rule for sign-ins); online,
 * the server checks the password.
 */
class IdleSignOut
{
    public const DEFAULT_MINUTES = 15;

    public const OPTIONS = [5, 10, 15, 30, 60];

    public const MAX_ATTEMPTS = 5;

    public const LOCKOUT_MINUTES = 15;

    /** Requests the page makes by itself: they never count as activity. */
    public const BACKGROUND_ROUTES = [
        'sync.step', 'sync.probe', 'notifications.feed', 'app-version.check',
        'evacuation-centers.board-refresh', 'evacuation-centers.households-refresh', 'system-notices.feed',
    ];

    /** Reachable while signed out after inactivity. */
    public const OPEN_ROUTES = ['unlock', 'unlock.submit', 'lock'];

    public function minutes(): int
    {
        $minutes = (int) AppState::get('idle_timeout_minutes', self::DEFAULT_MINUTES);

        return in_array($minutes, self::OPTIONS, true) ? $minutes : self::DEFAULT_MINUTES;
    }

    public function setMinutes(int $minutes): void
    {
        AppState::put('idle_timeout_minutes', in_array($minutes, self::OPTIONS, true) ? $minutes : self::DEFAULT_MINUTES);
    }

    public function touch(): void
    {
        AppState::put('last_activity_at', now()->toIso8601String());
    }

    /** No activity for longer than the timeout (also across a restart of the app). */
    public function expired(): bool
    {
        $last = AppState::get('last_activity_at');

        return $last !== null && Carbon::parse($last)->lt(now()->subMinutes($this->minutes()));
    }

    public function lock(LocalAuth $auth): void
    {
        if (! $auth->locked_at) {
            $auth->update(['locked_at' => now()]);
        }
    }

    /** After a successful online sign-in: remember the password's hash, start fresh. */
    public function signedInOnline(LocalAuth $auth, string $password): void
    {
        $auth->update(['password_hash' => Hash::make($password), 'locked_at' => null]);
        AppState::forget('unlock_failures');
        $this->touch();
    }

    public function lockedOut(): bool
    {
        $failures = AppState::get('unlock_failures');

        return ($failures['count'] ?? 0) >= self::MAX_ATTEMPTS
            && Carbon::parse($failures['at'])->gt(now()->subMinutes(self::LOCKOUT_MINUTES));
    }

    /** Checks the password on this computer; null when it cannot be checked here (no hash yet). */
    public function checkOffline(LocalAuth $auth, string $password): ?bool
    {
        if (! $auth->password_hash) {
            return null;
        }
        $ok = Hash::check($password, $auth->password_hash);
        if ($ok) {
            $auth->update(['locked_at' => null]);
            AppState::forget('unlock_failures');
            $this->touch();
        } else {
            $failures = AppState::get('unlock_failures', ['count' => 0]);
            AppState::put('unlock_failures', ['count' => ($failures['count'] ?? 0) + 1, 'at' => now()->toIso8601String()]);
        }

        return $ok;
    }
}
