<?php

namespace App\Support;

use App\Models\AppState;
use App\Models\LocalAuth;
use Illuminate\Support\Facades\Schema;

/**
 * What the server says about the account or itself, remembered so every
 * screen can follow (docs/backend/apps-api-2026-11-23.md 1):
 *  - a temporary password: nothing else works until it is changed;
 *  - maintenance mode: the administrator's message, until the server answers again.
 */
final class AccountState
{
    public static function passwordChangeRequired(bool $required = true): void
    {
        try {
            if (Schema::hasColumn('local_auth', 'must_change_password') && ($auth = LocalAuth::current())) {
                $auth->update(['must_change_password' => $required]);
            }
        } catch (\Throwable $e) {
            // Remembered at the next answer.
        }
    }

    public static function maintenance(string $message): void
    {
        try {
            AppState::put('maintenance', ['message' => $message, 'at' => now()->toIso8601String()]);
        } catch (\Throwable $e) {
        }
    }

    /** The administrator's maintenance message, if the server said so in the last 10 minutes. */
    public static function maintenanceMessage(): ?string
    {
        try {
            $state = AppState::get('maintenance');
        } catch (\Throwable $e) {
            return null;
        }
        if (! $state || now()->subMinutes(10)->gt(\Illuminate\Support\Carbon::parse($state['at']))) {
            return null;
        }

        return $state['message'] ?: 'The E-LIKAS server is under maintenance. Your work stays on this computer.';
    }

    public static function serverAnswered(): void
    {
        try {
            AppState::forget('maintenance');
        } catch (\Throwable $e) {
        }
    }
}
