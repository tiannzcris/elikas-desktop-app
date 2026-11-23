<?php

namespace App\Services;

use App\Exceptions\CentralApiException;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\ServerSnapshot;

/**
 * The official's dashboard figures from the server, copied for offline
 * reading (docs/backend/apps-api-2026-11-24.md 7.1): todo[] (what the
 * barangay still has to do, done on the web), each center's readiness, and
 * the latest alerts with their target (GET /alerts: target_barangay, 7.5).
 */
class BarangayDashboard
{
    /** centers[].readiness → label and tone. */
    public const READINESS = [
        'ready' => ['Ready', 'badge-success'],
        'below' => ['Below standard', 'badge-warning'],
        'no_checklist' => ['No facilities checklist', 'badge-neutral'],
        'no_capacity' => ['No capacity', 'badge-neutral'],
    ];

    public const TODO_KINDS = ['center_capacity', 'center_location', 'center_checklist', 'supplies_short'];

    public function __construct(private CentralApiService $api) {}

    private static function key(EvacuationEvent $event): string
    {
        return 'barangay_dashboard.'.$event->remote_id;
    }

    public function refresh(LocalAuth $auth, EvacuationEvent $event): void
    {
        $dashboard = $this->api->send('get', 'dashboard/barangay', $auth->api_token, ['evacuation_event_id' => $event->remote_id], 15)['data'] ?? [];

        // The staff list carries target_barangay; the dashboard's 3 alerts do not.
        try {
            $list = $this->api->send('get', 'alerts', $auth->api_token, ['per_page' => 3], 10)['data'] ?? [];
            $alerts = array_values($list['data'] ?? (array_is_list($list) ? $list : []));
        } catch (CentralApiException $e) {
            if ($e->isOffline() || $e->kind === CentralApiException::UNAUTHENTICATED) {
                throw $e;
            }
            $alerts = null;
        }

        ServerSnapshot::keep(self::key($event), ['dashboard' => $dashboard, 'alerts' => $alerts]);
    }

    /** The selected event's copy (best effort; no connection stops it). */
    public function refreshSelected(LocalAuth $auth): void
    {
        if (! $event = EvacuationEvent::working()) {
            return;
        }
        try {
            $this->refresh($auth, $event);
        } catch (CentralApiException $e) {
            if ($e->isOffline() || $e->kind === CentralApiException::UNAUTHENTICATED) {
                throw $e;
            }
        }
    }

    /** @return array{has_copy: bool, fetched_at: mixed, todo: array, readiness: array<int, string|null>, alerts: array} */
    public function forEvent(?EvacuationEvent $event): array
    {
        $snapshot = $event ? ServerSnapshot::named(self::key($event)) : null;
        $dashboard = $snapshot?->payload['dashboard'] ?? [];

        return [
            'has_copy' => $snapshot !== null,
            'fetched_at' => $snapshot?->fetched_at,
            // Unknown kinds are ignored: more may be added.
            'todo' => collect($dashboard['todo'] ?? [])->filter(fn ($t) => in_array($t['kind'] ?? null, self::TODO_KINDS, true))->values()->all(),
            'readiness' => collect($dashboard['centers'] ?? [])->mapWithKeys(fn ($c) => [(int) $c['id'] => $c['readiness'] ?? null])->all(),
            // Staff alerts with their target, else the dashboard's own 3 (no target there).
            'alerts' => $snapshot?->payload['alerts'] ?? ($dashboard['alerts'] ?? []),
        ];
    }

    /** "Whole city", "Barangay Tuburan", or null when the row does not say. */
    public static function target(array $alert): ?string
    {
        if (! array_key_exists('target_barangay', $alert)) {
            return null;
        }

        return $alert['target_barangay'] ? 'Barangay '.$alert['target_barangay']['name'] : 'Whole city';
    }
}
