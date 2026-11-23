<?php

namespace App\Services;

use App\Exceptions\CentralApiException;
use App\Models\AppState;
use App\Models\DownloadLog;
use App\Models\EcBoardEntry;
use App\Models\EvacuationEvent;
use App\Models\Family;
use App\Models\LocalAuth;
use App\Models\PendingCheckOut;
use App\Models\PendingHandout;
use App\Models\PendingListCheckIn;
use App\Models\PendingPrivateHouseFamily;
use App\Models\PendingReEnrollment;
use App\Support\EventLock;
use App\Support\Plural;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Sends this device's queued records to the central server, one per call
 * (step()), so the local server -- which answers one request at a time --
 * stays responsive while a long queue goes out.
 *
 * Order: oldest first, across every kind of record. A record that depends
 * on another one not yet accepted (a second person in a household still
 * waiting to be sent) is skipped until that one is in.
 *
 * Every record carries its own client_ref, so sending one again after a
 * lost answer returns the first result and never creates a duplicate.
 *
 * What a refusal does:
 *  - no connection, or a server error (5xx): the run stops; the record
 *    keeps its place and goes with the next run.
 *  - 401: the run stops; log in again. Nothing is marked.
 *  - 403, 404, 410, 422: the record is marked failed with the server's own
 *    words, and waits for the user (try again, edit or remove).
 *  - 409: the record is marked "decision" (a possible duplicate family).
 */
class SyncService
{
    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const DECISION = 'decision';

    /** The server refused because the event is closed or none is open. */
    public const EVENT_CLOSED = 'event_closed';

    public const NO_OPEN_EVENT = EvacuationEvent::NO_OPEN_EVENT;

    /**
     * The server refused because the event is being monitored: the action
     * opens when CSWD starts the evacuation. Goes again by itself once this
     * device sees the event active.
     */
    public const EVENT_LOCKED = 'event_locked';

    public const EMPTY = 'empty';

    public const STOPPED = 'stopped';

    public function __construct(private CentralApiService $api) {}

    /**
     * Every record not yet accepted, oldest first.
     *
     * @return Collection<int, array{key: string, model: Model, kind: string}>
     */
    public function unsent(): Collection
    {
        $entries = EcBoardEntry::whereNull('synced_at')
            ->with(['evacuationEvent', 'evacuationCenter', 'household.evacuees', 'household.barangay'])
            ->get()->map(fn ($m) => ['key' => 'entry:'.$m->id, 'model' => $m, 'kind' => 'entry']);

        $families = Family::whereNull('synced_at')->where('created_via_ec_board', false)
            ->with(['evacuees', 'barangay', 'evacuationEvent', 'evacuationCenter'])
            ->get()->map(fn ($m) => ['key' => 'family:'.$m->id, 'model' => $m, 'kind' => 'family']);

        $returning = PendingReEnrollment::whereNull('synced_at')->with(['evacuationEvent', 'evacuationCenter'])
            ->get()->map(fn ($m) => ['key' => 'returning:'.$m->id, 'model' => $m, 'kind' => 'returning']);

        $privateHouses = PendingPrivateHouseFamily::whereNull('synced_at')->with('evacuationEvent')
            ->get()->map(fn ($m) => ['key' => 'private:'.$m->id, 'model' => $m, 'kind' => 'private']);

        $checkOuts = PendingCheckOut::whereNull('synced_at')->with(['evacuationEvent', 'evacuationCenter', 'household'])
            ->get()->map(fn ($m) => ['key' => 'checkout:'.$m->id, 'model' => $m, 'kind' => 'checkout']);

        $checkIns = PendingListCheckIn::whereNull('synced_at')->with(['evacuationEvent', 'evacuationCenter'])
            ->get()->map(fn ($m) => ['key' => 'checkin:'.$m->id, 'model' => $m, 'kind' => 'checkin']);

        $handouts = PendingHandout::whereNull('synced_at')->with('evacuationEvent')
            ->get()->map(fn ($m) => ['key' => 'handout:'.$m->id, 'model' => $m, 'kind' => 'handout']);

        // Files saved here, for the server's activity log.
        $downloads = DownloadLog::whereNull('reported_at')->get()->map(fn ($m) => ['key' => 'download:'.$m->id, 'model' => $m, 'kind' => 'download']);

        return $entries->concat($families)->concat($returning)->concat($privateHouses)->concat($checkOuts)->concat($checkIns)->concat($handouts)->concat($downloads)
            ->sortBy(fn ($item) => [$item['model']->created_at?->getTimestamp() ?? 0, $item['model']->id])
            ->values();
    }

    /** Waiting to be sent (not failed, not waiting for a decision). */
    public function waiting(): Collection
    {
        return $this->unsent()->filter(fn ($item) => $item['model']->sync_state === null)->values();
    }

    /** Refused by the server or waiting for a decision: the user's turn. */
    public function needsAttention(): Collection
    {
        return $this->unsent()->filter(fn ($item) => $item['model']->sync_state !== null)->values();
    }

    /** Puts every failed record back in line (the user pressed Sync now or Try again). */
    public function retryFailed(?string $key = null): void
    {
        foreach ($this->needsAttention() as $item) {
            // An "event closed" answer is not retried by Sync now (it would be
            // refused again every time); only Try again on that one record.
            $retryable = $item['model']->sync_state === self::FAILED
                || ($key !== null && $item['model']->sync_state === self::EVENT_CLOSED);
            if ($retryable && ($key === null || $key === $item['key'])) {
                $item['model']->update(['sync_state' => null]);
            }
        }
    }

    /**
     * The server's "no open event" or "this event is closed" refusal
     * (EnsureEventOpen and the closed-event checks on the backend).
     */
    public static function isEventClosedAnswer(CentralApiException $e): bool
    {
        return in_array(EventLock::fromException($e), [EventLock::CLOSED, EventLock::NO_OPEN_EVENT], true);
    }

    /** "<event> is being monitored. <What> opens when CSWD starts the evacuation." */
    public static function isMonitoringAnswer(CentralApiException $e): bool
    {
        return EventLock::fromException($e) === EventLock::MONITORING;
    }

    /** Records held back while their event was monitored go again once it is active. */
    public function releaseUnlocked(): void
    {
        foreach ($this->unsent() as $item) {
            if ($item['model']->sync_state === self::EVENT_LOCKED && $item['model']->evacuationEvent?->status === 'active') {
                $item['model']->update(['sync_state' => null]);
            }
        }
    }

    /** The record this one has to wait for, if any (not yet accepted). */
    public function blockedBy(array $item): ?Model
    {
        if ($item['kind'] === 'checkout' && ! $item['model']->family_remote_id) {
            $household = $item['model']->household;
            if ($household && ! $household->isSynced()) {
                return $household;
            }
        }

        if ($item['kind'] === 'entry') {
            $entry = $item['model'];
            if ($entry->household_family_local_id && ! $entry->originated_household) {
                $household = Family::find($entry->household_family_local_id);
                if ($household && ! $household->isSynced()) {
                    return $household;
                }
            }
        }

        return null;
    }

    /**
     * Sends the next record that can go.
     *
     * @return array{status: string, key?: string, label?: string, message?: string, stop?: string, remaining: int}
     */
    public function step(LocalAuth $auth): array
    {
        $this->releaseUnlocked();
        $item = $this->waiting()->first(fn ($candidate) => $this->blockedBy($candidate) === null);
        if (! $item) {
            // Everything is in: closed events' data can leave this computer.
            if ($this->needsAttention()->isEmpty()) {
                app(LocalData::class)->pruneClosedEvents();
            }

            return ['status' => self::EMPTY, 'remaining' => 0];
        }

        $model = $item['model'];
        $label = $this->label($item);

        try {
            $this->send($item, $auth);
            AppState::put('last_sync_at', now()->toIso8601String());

            return ['status' => self::SENT, 'key' => $item['key'], 'label' => $label, 'remaining' => $this->remaining()];
        } catch (CentralApiException $e) {
            if ($e->isOffline() || $e->kind === CentralApiException::SERVER) {
                $model->update(['sync_error' => $e->getMessage()]);

                return ['status' => self::STOPPED, 'stop' => $e->isOffline() ? 'offline' : 'server', 'message' => $e->getMessage(), 'remaining' => $this->remaining()];
            }
            if ($e->kind === CentralApiException::UNAUTHENTICATED) {
                return ['status' => self::STOPPED, 'stop' => 'auth', 'message' => 'Your session has expired. Please log in again to continue syncing.', 'remaining' => $this->remaining()];
            }

            $state = match (true) {
                $e->kind === CentralApiException::CONFLICT => self::DECISION,
                self::isEventClosedAnswer($e) => self::EVENT_CLOSED,
                self::isMonitoringAnswer($e) => self::EVENT_LOCKED,
                default => self::FAILED,
            };
            $model->update(['sync_state' => $state, 'sync_error' => $e->getMessage()]);
            // The server says the event is closed (or none is open): this
            // device now knows too, so the board turns read-only for it.
            if ($state === self::EVENT_CLOSED && $model->evacuationEvent) {
                $model->evacuationEvent->update(['status' => 'closed']);
            }
            // Monitored on the server: this device waits for Start evacuation too.
            if ($state === self::EVENT_LOCKED && $model->evacuationEvent) {
                $model->evacuationEvent->update(['status' => 'monitoring']);
            }

            return ['status' => $state, 'key' => $item['key'], 'label' => $label, 'message' => $e->getMessage(), 'remaining' => $this->remaining()];
        } catch (\RuntimeException $e) {
            // A record this device can't build yet (e.g. its family isn't in).
            $model->update(['sync_state' => self::FAILED, 'sync_error' => $e->getMessage()]);

            return ['status' => self::FAILED, 'key' => $item['key'], 'label' => $label, 'message' => $e->getMessage(), 'remaining' => $this->remaining()];
        }
    }

    /**
     * Sends everything that can go, in one call (the no-JavaScript path and
     * the tests). The page normally drives step() itself.
     *
     * @return array{sent: int, failed: int, stop: ?string, message: ?string}
     */
    public function runAll(LocalAuth $auth): array
    {
        $summary = ['sent' => 0, 'failed' => 0, 'stop' => null, 'message' => null];

        // A bounded loop: each step either sends, marks or stops.
        for ($i = 0; $i < 1000; $i++) {
            $result = $this->step($auth);
            match ($result['status']) {
                self::SENT => $summary['sent']++,
                self::FAILED, self::DECISION, self::EVENT_CLOSED, self::EVENT_LOCKED => $summary['failed']++,
                default => null,
            };
            if (in_array($result['status'], [self::EMPTY, self::STOPPED], true)) {
                $summary['stop'] = $result['stop'] ?? null;
                $summary['message'] = $result['message'] ?? null;
                break;
            }
        }

        return $summary;
    }

    public function remaining(): int
    {
        return $this->waiting()->count();
    }

    public function label(array $item): string
    {
        $model = $item['model'];

        return match ($item['kind']) {
            'entry' => trim((EcBoardEntry::AGE_BRACKETS[$model->age_bracket] ?? $model->age_bracket).', '.$model->sex)
                .' · '.$model->householdLabel(),
            // Full registrations were removed in 2.0; ones queued before the
            // upgrade are still sent so nothing already entered is lost.
            'family' => 'Family '.$model->displayName().' (registered before 2.0)',
            'private' => 'Private house: '.$model->label().' · '.Plural::of($model->personCount(), 'person', 'people').' (with '.$model->host_name.')',
            'download' => 'File '.($model->action ?: 'saved').': '.$model->file_name,
            'handout' => 'Relief box: '.$model->family_label.($model->round_number ? ' (Round '.$model->round_number.')' : ''),
            'checkin' => 'Check-in: '.$model->family_label.' · '.Plural::of($model->members_count, 'person', 'people').' (listed family)',
            'checkout' => 'Check-out: '.$model->family_label.' ('.(PendingCheckOut::STATUSES[$model->status] ?? $model->status).')',
            'returning' => 'Returning family '.$model->family_name.' · '.Plural::of(count($model->members), 'person', 'people'),
            default => $item['key'],
        };
    }

    public function place(array $item): string
    {
        return match ($item['kind']) {
            'private' => 'Private house',
            'checkin' => $item['model']->destination(),
            'handout' => 'Relief boxes',
            'download' => 'Activity log',
            default => $item['model']->evacuationCenter?->name ?? '',
        };
    }

    private function send(array $item, LocalAuth $auth): void
    {
        $model = $item['model'];

        if ($item['kind'] === 'private') {
            $data = $this->api->addPrivateHouseFamily($auth->api_token, $model->barangay_remote_id, $model->toSyncPayload() + ['client_ref' => $model->client_ref]);
            $model->update(['remote_family_id' => $data['id'] ?? null, 'synced_at' => now(), 'sync_error' => null, 'sync_state' => null]);

            return;
        }

        if ($item['kind'] === 'download') {
            $this->api->send('post', 'file-downloads', $auth->api_token, $model->toSyncPayload());
            $model->update(['reported_at' => now(), 'sync_error' => null, 'sync_state' => null]);

            return;
        }

        if ($item['kind'] === 'handout') {
            $id = app(BoxRelief::class)->send($auth, $model);
            $model->update(['handout_remote_id' => $id, 'synced_at' => now(), 'sync_error' => null, 'sync_state' => null]);

            return;
        }

        if ($item['kind'] === 'checkin') {
            $registered = app(FamiliesToEvacuate::class)->sendCheckIn($auth, $model);
            $model->update(['registered_family_id' => $registered, 'synced_at' => now(), 'sync_error' => null, 'sync_state' => null]);

            return;
        }

        if ($item['kind'] === 'checkout') {
            $familyId = $model->familyRemoteId();
            if (! $familyId) {
                throw new \RuntimeException('This family is no longer on this device. Remove the check-out and do it again.');
            }
            try {
                $result = $this->api->checkOutFamily($auth->api_token, $familyId, $model->status,
                    $model->evacuationCenter->remote_id, $model->evacuationEvent->remote_id);
            } catch (CentralApiException $e) {
                // "No one in this family is still checked in": already done
                // (an earlier try went through, or someone did it elsewhere).
                if (($e->errors()['results'][0]['reason_code'] ?? null) !== 'none_checked_in') {
                    throw $e;
                }
                $result = $e->errors()['results'][0];
            }
            $model->update(['family_remote_id' => $familyId, 'people_checked_out' => $result['people_checked_out'] ?? 0,
                'synced_at' => now(), 'sync_error' => null, 'sync_state' => null]);

            return;
        }

        if ($item['kind'] === 'returning') {
            try {
                $data = $this->api->reEnroll($auth->api_token, $model->evacuationCenter->remote_id, $model->toSyncPayload() + ['client_ref' => $model->client_ref]);
            } catch (CentralApiException $e) {
                // 409: a family already in this event may be the same one.
                // Keep the server's candidates for the decision on the Sync page.
                if ($e->kind === CentralApiException::CONFLICT) {
                    $model->update(['possible_duplicates' => $e->data()['possible_duplicates'] ?? []]);
                }
                throw $e;
            }
            $model->update(['remote_family_id' => $data['family']['id'] ?? null, 'synced_at' => now(), 'sync_error' => null, 'sync_state' => null]);

            return;
        }

        if ($item['kind'] === 'family') {
            $remoteId = $this->api->registerFamily($auth->api_token, $model->toSyncPayload() + ['client_ref' => $model->client_ref]);
            $model->update(['remote_id' => $remoteId, 'synced_at' => now(), 'sync_error' => null, 'sync_state' => null]);
            $model->evacuees()->update(['synced_at' => now()]);

            return;
        }

        // A fresh read: a household sent earlier in this run is synced now.
        $model->unsetRelation('household');
        $result = $this->api->addEvacuee($auth->api_token, $model->evacuationCenter->remote_id, $model->toSyncPayload() + ['client_ref' => $model->client_ref]);
        $model->update(['remote_id' => $result['evacuee_id'], 'synced_at' => now(), 'sync_error' => null, 'sync_state' => null]);

        // The household this entry created gets the id the server just gave it.
        if ($model->originated_household && $model->household_family_local_id) {
            Family::whereKey($model->household_family_local_id)->update([
                'remote_id' => $result['family_id'], 'synced_at' => now(), 'sync_error' => null, 'sync_state' => null,
            ]);
        }
    }
}
