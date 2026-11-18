<?php

namespace App\Services;

use App\Exceptions\CentralApiException;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\PendingPrivateHouseFamily;
use App\Models\ServerSnapshot;
use Illuminate\Support\Collection;

/**
 * The official's barangay's families in private houses (with relatives or
 * friends, outside any center): the server's last list for each open event
 * (GET /private-house-families) plus families added here and not yet sent.
 */
class PrivateHouses
{
    public function __construct(private CentralApiService $api) {}

    private static function key(EvacuationEvent $event): string
    {
        return "private_houses.{$event->id}";
    }

    /** Keeps the list for every open event. Stops at no connection or a dead login. */
    public function refresh(LocalAuth $auth): void
    {
        foreach (EvacuationEvent::where('status', '!=', 'closed')->get() as $event) {
            try {
                ServerSnapshot::keep(self::key($event), $this->api->fetchPrivateHouses($auth->api_token, $event->remote_id, (int) $auth->barangay_id));
            } catch (CentralApiException $e) {
                if ($e->isOffline() || $e->kind === CentralApiException::UNAUTHENTICATED) {
                    throw $e;
                }
            }
        }
    }

    /**
     * @return array{families: Collection, family_count: int, person_count: int, waiting: int, fetched_at: ?\Illuminate\Support\Carbon}
     */
    public function forEvent(?EvacuationEvent $event, ?int $barangayRemoteId): array
    {
        if (! $event) {
            return ['families' => collect(), 'family_count' => 0, 'person_count' => 0, 'waiting' => 0, 'fetched_at' => null];
        }

        $snapshot = ServerSnapshot::named(self::key($event));
        $server = collect($snapshot?->payload['families'] ?? [])->map(fn (array $f) => [
            'label' => $f['label'] ?? 'Family #'.$f['id'],
            'persons' => (int) ($f['member_count'] ?? 0),
            'host' => trim(($f['host_name'] ?? '').(! empty($f['host_purok']['name']) ? ' · '.$f['host_purok']['name'] : '').(! empty($f['host_address']) ? ' · '.$f['host_address'] : '')),
            'incomplete' => (bool) ($f['incomplete'] ?? false),
            'waiting' => false,
        ]);

        $pending = PendingPrivateHouseFamily::where('evacuation_event_id', $event->id)
            ->when($barangayRemoteId, fn ($q) => $q->where('barangay_remote_id', $barangayRemoteId))
            ->whereNull('synced_at')->get()
            ->map(fn (PendingPrivateHouseFamily $f) => [
                'label' => $f->label(),
                'persons' => $f->personCount(),
                'host' => $f->host_name.($f->host_address ? ' · '.$f->host_address : ''),
                'incomplete' => true,
                'waiting' => true,
            ]);

        // The server's per-barangay totals count only families still checked in.
        $row = collect($snapshot?->payload['barangays'] ?? [])->first(fn ($r) => (int) ($r['barangay']['id'] ?? 0) === (int) $barangayRemoteId);

        return [
            'families' => $pending->concat($server)->values(),
            'family_count' => (int) ($row['families'] ?? $server->count()) + $pending->count(),
            'person_count' => (int) ($row['persons'] ?? $server->sum('persons')) + $pending->sum('persons'),
            'waiting' => $pending->count(),
            'fetched_at' => $snapshot?->fetched_at,
        ];
    }
}
