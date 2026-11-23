<?php

namespace App\Services;

use App\Exceptions\CentralApiException;
use App\Models\EvacuationEvent;
use App\Models\LocalAuth;
use App\Models\ServerSnapshot;

/**
 * The barangay official's final count for the selected event: the system's
 * count now (GET /count-submissions/computed, with late entries and the
 * system checks), and the barangay's submissions (GET /count-submissions).
 * Copied for reading offline; submitting is online only.
 *
 * Submitting: POST /count-submissions {evacuation_event_id, note?}
 * (docs/backend/apps-api-2026-11-24.md 2). The server counts the barangay
 * at that moment; the numbers are never sent. Safe to retry without
 * client_ref: an identical second submission answers 422 "This count is
 * already waiting for CSWD, and nothing has changed since it was submitted."
 */
class BarangayFinalCount
{
    public function __construct(private CentralApiService $api) {}

    private static function key(EvacuationEvent $event): string
    {
        return 'final_count.'.$event->remote_id;
    }

    public function refresh(LocalAuth $auth, EvacuationEvent $event): void
    {
        $computed = $this->api->send('get', 'count-submissions/computed', $auth->api_token, ['evacuation_event_id' => $event->remote_id], 15)['data'] ?? [];
        $list = $this->api->send('get', 'count-submissions', $auth->api_token, ['evacuation_event_id' => $event->remote_id, 'per_page' => 20], 15)['data'] ?? [];

        ServerSnapshot::keep(self::key($event), [
            'computed' => $computed,
            'submissions' => array_values($list['data'] ?? (array_is_list($list) ? $list : [])),
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

    /**
     * What the page shows, from the copy: the system's count now, split in
     * centers and private houses when the server says so; the latest
     * submission and whether CSWD submitted it on the barangay's behalf;
     * late entries; the checks; and whether there is anything to submit.
     */
    public function forEvent(?EvacuationEvent $event): array
    {
        $snapshot = $event ? ServerSnapshot::named(self::key($event)) : null;
        $computed = $snapshot?->payload['computed'] ?? [];
        $figures = $computed['computed'] ?? $computed;
        $submissions = collect($snapshot?->payload['submissions'] ?? [])
            ->sortByDesc(fn ($s) => [$s['submitted_at'] ?? $s['created_at'] ?? '', $s['id'] ?? 0])->values();
        $latest = $computed['current_submission'] ?? $submissions->first();
        $late = $computed['late_entries'] ?? null;
        $lateTotal = (int) ($late['total'] ?? 0);
        $status = $latest['status'] ?? null;

        return [
            'has_copy' => $snapshot !== null,
            'fetched_at' => $snapshot?->fetched_at,
            'families' => $figures['families'] ?? null,
            'persons' => $figures['persons'] ?? null,
            'in_centers' => isset($figures['families_in_centers']) ? ['families' => $figures['families_in_centers'], 'persons' => $figures['persons_in_centers'] ?? null] : null,
            'in_private_houses' => isset($figures['families_in_private_houses']) ? ['families' => $figures['families_in_private_houses'], 'persons' => $figures['persons_in_private_houses'] ?? null] : null,
            'latest' => $latest,
            'on_behalf' => $latest ? self::onBehalfLine($latest) : null,
            'late' => $late,
            'late_total' => $lateTotal,
            'checks' => $computed['checks'] ?? null,
            'submissions' => $submissions,
            // A validated count with nothing new has nothing to submit (Nov 22).
            'nothing_new' => $status === 'validated' && $lateTotal === 0,
            'waiting_for_cswd' => $status === 'pending',
        ];
    }

    /**
     * "Submitted by CSWD on behalf of Bacong. Reason: …" for a count CSWD
     * submitted for the barangay (the note as the server wrote it), else null.
     */
    public static function onBehalfLine(array $submission): ?string
    {
        $note = trim((string) ($submission['note'] ?? ''));
        if (str_starts_with($note, 'Submitted by CSWD on behalf of')) {
            return $note;
        }
        if (! empty($submission['submitted_on_behalf'])) {
            $barangay = $submission['barangay']['name'] ?? null;

            return 'Submitted by CSWD on behalf of '.($barangay ?? 'the barangay').'.'.($note !== '' ? " {$note}" : '');
        }

        return null;
    }

    /** Puts a submission the server just answered at the top of the copy (the refresh may fail). */
    public function remember(EvacuationEvent $event, array $submission): void
    {
        $snapshot = ServerSnapshot::named(self::key($event));
        $payload = $snapshot?->payload ?? ['computed' => [], 'submissions' => []];
        $payload['submissions'] = array_values(array_merge([$submission], array_filter($payload['submissions'] ?? [], fn ($s) => ($s['id'] ?? null) !== ($submission['id'] ?? null))));
        unset($payload['computed']['current_submission']);
        ServerSnapshot::keep(self::key($event), $payload);
    }

    /** POST /count-submissions: the barangay's count now, for CSWD to validate. */
    public function submit(LocalAuth $auth, EvacuationEvent $event, ?string $note): array
    {
        return $this->api->send('post', 'count-submissions', $auth->api_token, array_filter([
            'evacuation_event_id' => $event->remote_id,
            'note' => $note,
        ]));
    }
}
