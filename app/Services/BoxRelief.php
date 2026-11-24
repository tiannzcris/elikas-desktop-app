<?php

namespace App\Services;

use App\Exceptions\CentralApiException;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\PendingHandout;
use App\Models\ServerSnapshot;
use App\Support\Plural;
use Illuminate\Support\Collection;

/**
 * Relief boxes for a barangay official (docs/backend/apps-api-2026-11-24.md 1):
 * one box per family per round, given from the boxes the barangay received.
 *
 * The family list of each round (GET /box-relief/rounds/{round}/families) is
 * copied for offline use. A box given here is queued (PendingHandout, with
 * client_ref) and shows at once: the family is marked given, the boxes left
 * go down, and the suggested order (priority, Next in line, higher-priority
 * families waiting) is worked out again from the copy.
 *
 * The rounds come from GET /box-relief/barangay?evacuation_event_id=:
 * data.rounds[], ordered by round_number, each {id, round_number,
 * opened_at, ...that round's figures, dispatches, shortfall_requests,
 * totals_given}, and data.need_basis (registered or validated). Checked
 * against the backend's BoxReliefController::barangay() and roundRow().
 */
class BoxRelief
{
    public const LABELS = [
        1 => 'Infant', 2 => 'Pregnant / lactating', 3 => 'PWD / senior', 4 => 'Child- or single-headed',
    ];

    public function __construct(private CentralApiService $api) {}

    private static function key(EvacuationEvent $event): string
    {
        return 'relief.'.$event->remote_id;
    }

    /** Reads the barangay's rounds and each round's family list again. */
    public function refresh(LocalAuth $auth, EvacuationEvent $event): void
    {
        $barangay = $this->api->send('get', 'box-relief/barangay', $auth->api_token, ['evacuation_event_id' => $event->remote_id], 15)['data'] ?? [];
        $rounds = self::roundsFrom($barangay);

        $families = [];
        foreach ($rounds as $round) {
            $families[(string) $round['id']] = $this->api->send('get', "box-relief/rounds/{$round['id']}/families", $auth->api_token, ['order' => 'priority'], 15)['data'] ?? [];
        }

        ServerSnapshot::keep(self::key($event), [
            'rounds' => $rounds,
            'need_basis' => $barangay['need_basis'] ?? null,
            'families' => $families,
        ]);
    }

    /** Every open event's copy (best effort per event; no connection stops it). */
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

    /** @return list<array{id: int, round_number: int|null}> rounds, newest last */
    public static function roundsFrom(array $barangay): array
    {
        return collect($barangay['rounds'] ?? [])->filter(fn ($r) => is_array($r) && ! empty($r['id']))
            ->map(fn ($r) => ['id' => (int) $r['id'], 'round_number' => isset($r['round_number']) ? (int) $r['round_number'] : null, 'opened_at' => $r['opened_at'] ?? null])
            ->unique('id')->sortBy(fn ($r) => [$r['round_number'] ?? 0, $r['id']])->values()->all();
    }

    /**
     * One round as this computer knows it: the server's copy with the boxes
     * given here applied, and the suggested order worked out again.
     *
     * @return array{has_copy: bool, fetched_at: mixed, rounds: array, round: ?array, figures: array, puroks: Collection, staying_elsewhere: array, next_family_id: ?int, need_basis: ?string, waiting: int}
     */
    public function forEvent(?EvacuationEvent $event, ?int $roundId = null, string $order = 'priority'): array
    {
        $snapshot = $event ? ServerSnapshot::named(self::key($event)) : null;
        $rounds = $snapshot?->payload['rounds'] ?? [];
        $round = collect($rounds)->firstWhere('id', $roundId) ?? collect($rounds)->last();
        $data = $round ? ($snapshot->payload['families'][(string) $round['id']] ?? []) : [];

        $local = $round ? PendingHandout::where('round_remote_id', $round['id'])->get()->keyBy('family_remote_id') : collect();
        $figures = ($data['figures'] ?? []) + ['received' => 0, 'given' => 0, 'left_to_give' => 0, 'need' => 0];

        // Families of this barangay's list, with the boxes given here.
        $puroks = collect($data['puroks'] ?? [])->map(function (array $group) use ($local) {
            $group['families'] = collect($group['families'] ?? [])->map(function (array $f) use ($local) {
                $mine = ! ($f['given'] ?? false) ? $local->get($f['id']) : null;
                $f['given_here'] = $mine;
                $f['waiting_to_sync'] = $mine && ! $mine->synced_at;
                $f['is_given'] = ($f['given'] ?? false) || $mine !== null;

                return $f;
            });

            return $group;
        });
        $appliedHere = $puroks->sum(fn ($g) => $g['families']->whereNotNull('given_here')->count());
        $figures['given'] += $appliedHere;
        $figures['left_to_give'] = max(0, (int) $figures['left_to_give'] - $appliedHere);

        // The suggested order again: tier first ("none" counts as 5), then each purok's own order.
        $all = $puroks->flatMap(fn ($g) => $g['families']);
        $tier = fn ($f) => $f['priority_tier'] ?? 5;
        $remaining = $all->reject(fn ($f) => $f['is_given']);
        $next = $remaining->contains('id', $data['next_family_id'] ?? null)
            ? $data['next_family_id']
            : $remaining->sortBy(fn ($f) => [$tier($f), $f['priority_order'] ?? 999, $f['id']])->first()['id'] ?? null;

        $puroks = $puroks->map(function (array $group) use ($remaining, $tier, $next, $figures, $order) {
            $group['families'] = $group['families']->map(function (array $f) use ($remaining, $tier, $next, $figures) {
                $f['higher_priority_waiting'] = $f['is_given'] ? 0 : $remaining->filter(fn ($o) => $tier($o) < $tier($f))->count();
                $f['next_in_line'] = $f['id'] === $next;
                $f['can_give'] = ! $f['is_given'] && $figures['left_to_give'] > 0 && ($f['may_give'] ?? true);

                return $f;
            })->sortBy(fn ($f) => $order === 'name' ? [mb_strtolower($f['head_name'] ?? $f['name'] ?? '')] : [$f['priority_order'] ?? 999, $f['id']])->values();
            $group['given'] = $group['families']->where('is_given', true)->count();

            return $group;
        });

        return [
            'has_copy' => $snapshot !== null,
            'fetched_at' => $snapshot?->fetched_at,
            'rounds' => $rounds,
            'round' => $round,
            'figures' => $figures,
            'puroks' => $puroks,
            'staying_elsewhere' => $data['staying_elsewhere'] ?? [],
            'next_family_id' => $next,
            'need_basis' => $snapshot?->payload['need_basis'] ?? null,
            'waiting' => $round ? PendingHandout::where('round_remote_id', $round['id'])->whereNull('synced_at')->count() : 0,
        ];
    }

    /** "2 higher-priority families have not received a box yet. Continue?" (section 1.3). */
    public static function outOfPriorityQuestion(int $waiting): string
    {
        return Plural::of($waiting, 'higher-priority family has', 'higher-priority families have').' not received a box yet. Continue?';
    }

    /** POST /box-relief/rounds/{round}/handouts {family_id, client_ref}; returns the handout's id. */
    public function send(LocalAuth $auth, PendingHandout $handout): ?int
    {
        $body = $this->api->send('post', "box-relief/rounds/{$handout->round_remote_id}/handouts", $auth->api_token, [
            'family_id' => $handout->family_remote_id,
            'client_ref' => $handout->client_ref,
        ]);

        return isset($body['data']['id']) ? (int) $body['data']['id'] : null;
    }
}
