<?php

namespace App\Services;

use App\Models\AppState;
use App\Models\CenterFamilySnapshot;
use App\Models\EvacuationCenterBreakdown;
use App\Models\EvacuationCenterSectoralSnapshot;
use App\Models\EvacuationEvent;
use App\Models\EcBoardEntry;
use App\Models\Family;
use App\Models\LocalAuth;
use App\Models\PendingCheckOut;
use App\Models\PendingListCheckIn;
use App\Models\PendingPrivateHouseFamily;
use App\Models\PendingReEnrollment;
use App\Models\ReturningFamilySnapshot;
use App\Models\ServerSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * What this device holds: records waiting to be sent, and copies of
 * server data. Queued records belong to the account that made them; they
 * stay through a logout so that account can send them at its next login.
 */
class LocalData
{
    /** Records on this device not yet accepted by the central server. */
    public function pendingCount(): int
    {
        return EcBoardEntry::whereNull('synced_at')->count()
            + Family::whereNull('synced_at')->where('created_via_ec_board', false)->count()
            + PendingReEnrollment::whereNull('synced_at')->count()
            + PendingCheckOut::whereNull('synced_at')->count()
            + PendingPrivateHouseFamily::whereNull('synced_at')->count()
            + PendingListCheckIn::whereNull('synced_at')->count();
    }

    /** Records the server refused or that wait for a decision (the Sync page's Needs attention). */
    public function failedCount(): int
    {
        return EcBoardEntry::whereNull('synced_at')->whereNotNull('sync_state')->count()
            + Family::whereNull('synced_at')->where('created_via_ec_board', false)->whereNotNull('sync_state')->count()
            + PendingReEnrollment::whereNull('synced_at')->whereNotNull('sync_state')->count()
            + PendingCheckOut::whereNull('synced_at')->whereNotNull('sync_state')->count()
            + PendingPrivateHouseFamily::whereNull('synced_at')->whereNotNull('sync_state')->count()
            + PendingListCheckIn::whereNull('synced_at')->whereNotNull('sync_state')->count();
    }

    /** @return array{id: int, name: string, email: string}|null */
    public function queueOwner(): ?array
    {
        return $this->pendingCount() > 0 ? AppState::get('queue_owner') : null;
    }

    /**
     * A different account may not log in while another account's records
     * wait here: they would be sent under the wrong name, or not at all.
     */
    public function blocksLoginOf(int $remoteUserId): ?array
    {
        $owner = $this->queueOwner();

        return $owner && (int) $owner['id'] !== $remoteUserId ? $owner : null;
    }

    /**
     * Called at logout: remembers who owns the queue and removes copies of
     * personal data that are already safe on the server.
     */
    public function closeSession(LocalAuth $auth): void
    {
        if ($this->pendingCount() > 0) {
            AppState::put('queue_owner', ['id' => (int) $auth->remote_user_id, 'name' => $auth->name, 'email' => $auth->email]);
        } else {
            AppState::forget('queue_owner');
        }

        $this->forgetSentRecords();
        CenterFamilySnapshot::query()->delete();
        ReturningFamilySnapshot::query()->delete();
        PendingReEnrollment::whereNotNull('synced_at')->delete();
        PendingCheckOut::whereNotNull('synced_at')->delete();
        PendingPrivateHouseFamily::whereNotNull('synced_at')->delete();
        PendingListCheckIn::whereNotNull('synced_at')->delete();
        ServerSnapshot::query()->delete();
    }

    /** Records of this event not yet accepted by the server (any kind). */
    public function unsentFor(EvacuationEvent $event): int
    {
        return EcBoardEntry::where('evacuation_event_id', $event->id)->whereNull('synced_at')->count()
            + Family::where('evacuation_event_id', $event->id)->whereNull('synced_at')->where('created_via_ec_board', false)->count()
            + PendingReEnrollment::where('evacuation_event_id', $event->id)->whereNull('synced_at')->count()
            + PendingCheckOut::where('evacuation_event_id', $event->id)->whereNull('synced_at')->count()
            + PendingPrivateHouseFamily::where('evacuation_event_id', $event->id)->whereNull('synced_at')->count()
            + PendingListCheckIn::where('evacuation_event_id', $event->id)->whereNull('synced_at')->count();
    }

    /**
     * Closed events whose records have all been sent: their personal data
     * leaves this computer (families, entries, copies of server lists and
     * boards). The event itself stays, so it can still be chosen to look
     * back at; the server has everything.
     *
     * @return list<string> the names of the events cleared
     */
    public function pruneClosedEvents(): array
    {
        $cleared = [];
        foreach (EvacuationEvent::where('status', 'closed')->get() as $event) {
            if ($this->unsentFor($event) > 0 || ! $this->holdsDataFor($event)) {
                continue;
            }
            DB::transaction(function () use ($event) {
                PendingCheckOut::where('evacuation_event_id', $event->id)->delete();
                PendingReEnrollment::where('evacuation_event_id', $event->id)->delete();
                PendingPrivateHouseFamily::where('evacuation_event_id', $event->id)->delete();
                PendingListCheckIn::where('evacuation_event_id', $event->id)->delete();
                EcBoardEntry::where('evacuation_event_id', $event->id)->delete();
                Family::where('evacuation_event_id', $event->id)->get()->each->delete();
                CenterFamilySnapshot::where('evacuation_event_id', $event->id)->delete();
                ReturningFamilySnapshot::where('evacuation_event_id', $event->id)->delete();
                EvacuationCenterBreakdown::where('evacuation_event_id', $event->id)->delete();
                EvacuationCenterSectoralSnapshot::where('evacuation_event_id', $event->id)->delete();
                ServerSnapshot::whereIn('key', self::snapshotKeys($event))->delete();
            });
            $cleared[] = $event->name;
        }

        return $cleared;
    }

    /** Whether anything about this event is still on this computer. */
    public function holdsDataFor(EvacuationEvent $event): bool
    {
        return EcBoardEntry::where('evacuation_event_id', $event->id)->exists()
            || Family::where('evacuation_event_id', $event->id)->exists()
            || PendingReEnrollment::where('evacuation_event_id', $event->id)->exists()
            || PendingCheckOut::where('evacuation_event_id', $event->id)->exists()
            || PendingPrivateHouseFamily::where('evacuation_event_id', $event->id)->exists()
            || PendingListCheckIn::where('evacuation_event_id', $event->id)->exists()
            || CenterFamilySnapshot::where('evacuation_event_id', $event->id)->exists()
            || ReturningFamilySnapshot::where('evacuation_event_id', $event->id)->exists()
            || EvacuationCenterBreakdown::where('evacuation_event_id', $event->id)->exists()
            || EvacuationCenterSectoralSnapshot::where('evacuation_event_id', $event->id)->exists()
            || ServerSnapshot::whereIn('key', self::snapshotKeys($event))->exists();
    }

    /** The copies of server pages kept per event (some by local id, some by server id). */
    private static function snapshotKeys(EvacuationEvent $event): array
    {
        return ["private_houses.{$event->id}", "transfers.{$event->id}",
            "families_to_evacuate.{$event->remote_id}", "final_count.{$event->remote_id}"];
    }

    /** Sent EC Board entries and families, except households a waiting entry still points to. */
    public function forgetSentRecords(): void
    {
        EcBoardEntry::whereNotNull('synced_at')->delete();

        $stillNeeded = EcBoardEntry::whereNull('synced_at')->whereNotNull('household_family_local_id')->pluck('household_family_local_id');
        Family::whereNotNull('synced_at')->whereNotIn('id', $stillNeeded)->get()->each->delete();
    }
}
