<?php

namespace App\Services;

use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\ServerSnapshot;

/**
 * Transfer requests of the selected event (GET /transfer-requests), kept
 * for offline reading with the time they were fetched. Deciding and
 * sending are online only; Accept and Decline are hidden while offline
 * because may_decide can be out of date.
 */
class Transfers
{
    public function __construct(private CentralApiService $api) {}

    private static function key(EvacuationEvent $event): string
    {
        return "transfers.{$event->id}";
    }

    /** Fetches the list now. Throws CentralApiException when it can't. */
    public function refresh(LocalAuth $auth, ?EvacuationEvent $event = null): void
    {
        $event ??= EvacuationEvent::working();
        if ($event) {
            ServerSnapshot::keep(self::key($event), $this->api->transferRequests($auth->api_token, $event->remote_id));
        }
    }

    /** @return array{requests: array, counts: array, fetched_at: ?\Illuminate\Support\Carbon} */
    public function forEvent(?EvacuationEvent $event): array
    {
        $snapshot = $event ? ServerSnapshot::named(self::key($event)) : null;

        return [
            'requests' => $snapshot?->payload['requests'] ?? [],
            'counts' => $snapshot?->payload['counts'] ?? [],
            'fetched_at' => $snapshot?->fetched_at,
        ];
    }

    /** Pending requests this account should look at (incoming for an official; every pending one for CSWD). */
    public function waitingCount(LocalAuth $auth, ?EvacuationEvent $event): int
    {
        $counts = $this->forEvent($event)['counts'];

        return (int) ($auth->isCityWide() ? ($counts['pending'] ?? 0) : ($counts['incoming_pending'] ?? 0));
    }
}
