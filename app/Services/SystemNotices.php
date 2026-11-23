<?php

namespace App\Services;

use App\Exceptions\CentralApiException;
use App\Models\AppState;
use App\Models\LocalAuth;
use Illuminate\Support\Carbon;

/**
 * Technical notices from the administrator (a new app version, maintenance
 * at 10 PM), separate from alerts: GET /system-notices, shown as a banner on
 * every page. Kept for offline; a notice past its end is not shown.
 */
class SystemNotices
{
    private const KEY = 'system_notices';

    public function __construct(private CentralApiService $api) {}

    public function refresh(LocalAuth $auth): void
    {
        try {
            $rows = $this->api->send('get', 'system-notices', $auth->api_token, [], 8)['data'] ?? [];
            AppState::put(self::KEY, array_values(array_filter((array) $rows, 'is_array')));
        } catch (CentralApiException $e) {
            if ($e->isOffline() || $e->kind === CentralApiException::UNAUTHENTICATED) {
                throw $e;
            }
            // An older server without notices: nothing to show.
        }
    }

    /** @return list<array{id: int, title: string, message: string}> the notices showing now */
    public function current(): array
    {
        try {
            $rows = AppState::get(self::KEY, []);
        } catch (\Throwable $e) {
            return [];
        }

        return array_values(array_filter((array) $rows, fn ($n) => empty($n['ends_at']) || Carbon::parse($n['ends_at'])->isFuture()));
    }
}
