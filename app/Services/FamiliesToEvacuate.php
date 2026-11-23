<?php

namespace App\Services;

use App\Exceptions\CentralApiException;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\PendingListAddition;
use App\Models\PendingListCheckIn;
use App\Models\Purok;
use App\Models\ServerSnapshot;
use Illuminate\Support\Collection;

/**
 * Families to evacuate (docs/backend/apps-api-2026-11-22.md 3): the
 * families a barangay expects to move in an event, per purok. A listed
 * family is never an evacuee, never in a total, until it is checked in.
 *
 * The list is copied for reading and checking in offline (refreshed at
 * login, with Refresh, and after every change). Adding a family and
 * checking one in are queued with their client_ref and sent by Sync (a
 * family added here can be checked in before it is sent; the check-in waits
 * for it). Removing and the high-risk marks are online only.
 */
class FamiliesToEvacuate
{
    public function __construct(private CentralApiService $api) {}

    private static function key(EvacuationEvent $event): string
    {
        return 'families_to_evacuate.'.$event->remote_id;
    }

    /** Reads the list of one event again (GET /families-to-evacuate). */
    public function refresh(LocalAuth $auth, EvacuationEvent $event): void
    {
        $data = $this->api->send('get', 'families-to-evacuate', $auth->api_token, ['evacuation_event_id' => $event->remote_id], 15)['data'] ?? [];
        ServerSnapshot::keep(self::key($event), $data);
    }

    /** Every open event's list (best effort per event; no connection stops it). */
    public function refreshOpen(LocalAuth $auth): void
    {
        foreach (EvacuationEvent::where('status', '!=', 'closed')->get() as $event) {
            try {
                $this->refresh($auth, $event);
            } catch (CentralApiException $e) {
                if ($e->isOffline() || $e->kind === CentralApiException::UNAUTHENTICATED) {
                    throw $e;
                }
            }
        }
    }

    /**
     * The list as this device knows it: the server's copy, with check-ins
     * made here and not yet sent shown as "checked in, waiting to sync".
     * Grouped per purok, high-risk puroks first, then by name.
     *
     * @return array{fetched_at: ?\Illuminate\Support\Carbon, summary: array, groups: Collection, has_copy: bool}
     */
    public function forEvent(?EvacuationEvent $event, int $barangayRemoteId): array
    {
        $snapshot = $event ? ServerSnapshot::named(self::key($event)) : null;
        $pending = $event ? PendingListCheckIn::where('evacuation_event_id', $event->id)->get()->keyBy('listed_family_remote_id') : collect();
        $puroks = Purok::where('barangay_remote_id', $barangayRemoteId)->get()->keyBy('remote_id');

        // Families added here that the copy does not have yet.
        $known = collect($snapshot?->payload['families'] ?? [])->pluck('id')->all();
        $added = $event ? PendingListAddition::where('evacuation_event_id', $event->id)->get()
            ->reject(fn (PendingListAddition $a) => $a->listed_family_remote_id && in_array($a->listed_family_remote_id, $known, true)) : collect();
        $localCheckIns = $event ? PendingListCheckIn::where('evacuation_event_id', $event->id)->whereNotNull('pending_list_addition_id')->get()->keyBy('pending_list_addition_id') : collect();
        $addedRows = $added->map(function (PendingListAddition $a) use ($puroks, $localCheckIns) {
            $purok = $puroks->get($a->purok_remote_id);
            $checkIn = $localCheckIns->get($a->id) ?? ($a->listed_family_remote_id ? PendingListCheckIn::where('listed_family_remote_id', $a->listed_family_remote_id)->first() : null);

            return [
                'id' => 'local-'.$a->id, 'head' => $a->label, 'members_count' => (int) $a->members_count,
                'purok' => $purok ? ['id' => $purok->remote_id, 'name' => $purok->name] : null,
                'contact_number' => $a->contact_number, 'note' => $a->note, 'from_past_event' => $a->source_family_id ? ['family_id' => $a->source_family_id] : null,
                'status' => 'expected', 'added_here' => $a, 'waiting_add' => ! $a->synced_at,
            ];
        });

        $families = collect($snapshot?->payload['families'] ?? [])->concat($addedRows)->map(function (array $f) use ($pending, $puroks, $localCheckIns) {
            $local = isset($f['added_here']) ? $localCheckIns->get($f['added_here']->id) : $pending->get($f['id']);
            $purok = $puroks->get($f['purok']['id'] ?? null);

            return $f + [
                'added_here' => null,
                'waiting_add' => false,
                'members_count' => (int) ($f['members_count'] ?? 0),
                'waiting_check_in' => $local && ! $local->synced_at ? $local : null,
                'is_checked_in' => ($f['status'] ?? 'expected') === 'checked_in' || $local !== null,
                // The device's marks win: they may have been changed here since the copy.
                'purok_high_risk' => $purok ? $purok->isHighRisk() : (bool) ($f['purok']['is_high_risk'] ?? false),
            ];
        });

        $groups = $families->groupBy(fn ($f) => $f['purok']['id'] ?? 0)->map(function (Collection $rows, $purokId) use ($puroks) {
            $purok = $puroks->get($purokId);
            $first = $rows->first();

            return [
                'purok' => $purok,
                'name' => $purok?->name ?? ($first['purok']['name'] ?? 'No purok'),
                'high_risk' => $first['purok_high_risk'],
                'risk' => $purok?->riskLabel() ?? ($first['purok']['risk'] ?? null),
                'families' => $rows->sortBy(fn ($f) => mb_strtolower($f['head'] ?? ''))->values(),
                'persons' => $rows->sum('members_count'),
                'checked_in' => $rows->where('is_checked_in', true)->count(),
            ];
        })->sortBy(fn ($g) => [$g['high_risk'] ? 0 : 1, $g['purok'] ? 0 : 1, mb_strtolower($g['name'])])->values();

        return [
            'fetched_at' => $snapshot?->fetched_at,
            'has_copy' => $snapshot !== null,
            'can' => $snapshot?->payload['can'] ?? null,
            'summary' => [
                'families' => $families->count(),
                'persons' => $families->sum('members_count'),
                'checked_in' => $families->where('is_checked_in', true)->count(),
            ],
            'groups' => $groups,
        ];
    }

    /** Sends one queued addition (POST /families-to-evacuate with client_ref); returns the listed family's id. */
    public function sendAddition(LocalAuth $auth, PendingListAddition $addition): ?int
    {
        $body = $this->api->send('post', 'families-to-evacuate', $auth->api_token, $addition->toSyncPayload());

        return isset($body['data']['id']) ? (int) $body['data']['id'] : null;
    }

    /** DELETE /families-to-evacuate/{id}; a 404 (already removed, a retry) counts as done. */
    public function remove(LocalAuth $auth, int $listedId): void
    {
        try {
            $this->api->send('delete', "families-to-evacuate/{$listedId}", $auth->api_token);
        } catch (CentralApiException $e) {
            if ($e->kind !== CentralApiException::NOT_FOUND) {
                throw $e;
            }
        }
    }

    /** GET /families-to-evacuate/past-families?search=: one per household, from closed events. */
    public function pastFamilies(LocalAuth $auth, string $search): array
    {
        $data = $this->api->send('get', 'families-to-evacuate/past-families', $auth->api_token, ['search' => $search], 10)['data'] ?? [];

        return array_values($data['families'] ?? $data);
    }

    /** PATCH /puroks/{id} with the high-risk marks; the device's copy follows. */
    public function markPurok(LocalAuth $auth, Purok $purok, bool $flood, bool $landslide): void
    {
        $row = $this->api->send('patch', "puroks/{$purok->remote_id}", $auth->api_token, [
            'high_risk_flood' => $flood, 'high_risk_landslide' => $landslide,
        ])['data'] ?? [];

        $purok->update([
            'high_risk_flood' => (bool) ($row['high_risk_flood'] ?? $flood),
            'high_risk_landslide' => (bool) ($row['high_risk_landslide'] ?? $landslide),
        ]);
    }

    /** Sends one queued check-in; returns the registered family's id. */
    public function sendCheckIn(LocalAuth $auth, PendingListCheckIn $checkIn): ?int
    {
        $listedId = $checkIn->listed_family_remote_id ?? $checkIn->addition?->listed_family_remote_id;
        if (! $listedId) {
            throw new \RuntimeException('The family this check-in is for is not on the server yet.');
        }
        $body = $this->api->send('post', "families-to-evacuate/{$listedId}/check-in", $auth->api_token, $checkIn->toSyncPayload());
        $id = $body['data']['registered_family_id'] ?? $body['registered_family_id'] ?? $body['data']['family_id'] ?? null;

        return $id !== null ? (int) $id : null;
    }
}
