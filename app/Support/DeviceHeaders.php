<?php

namespace App\Support;

use App\Models\AppState;
use App\Services\LocalData;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The headers every request to the server carries, for the administrator's
 * "Devices and sync" view (docs/backend/apps-api-2026-11-23.md 2): which
 * computer, which version, and how its queue stands. Counts only, never
 * what the records are.
 */
final class DeviceHeaders
{
    /** A stable id made once per installation (a UUID). */
    public static function deviceId(): string
    {
        $id = AppState::get('device_id');
        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9._:-]{4,100}$/', $id)) {
            $id = (string) Str::uuid();
            AppState::put('device_id', $id);
        }

        return $id;
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        $headers = [
            'X-App-Version' => (string) config('nativephp.version'),
            'X-App-Platform' => 'desktop',
        ];

        try {
            if (! Schema::hasTable('app_state')) {
                return $headers;
            }
            $local = app(LocalData::class);
            $failed = $local->failedCount();
            $headers['X-Device-Id'] = self::deviceId();
            $headers['X-Sync-Pending'] = (string) max(0, $local->pendingCount() - $failed);
            $headers['X-Sync-Failed'] = (string) $failed;
            if ($last = AppState::get('last_sync_at')) {
                $headers['X-Last-Sync'] = (string) $last;
            }
        } catch (\Throwable $e) {
            // A database being updated: the version headers still go.
        }

        return $headers;
    }
}
