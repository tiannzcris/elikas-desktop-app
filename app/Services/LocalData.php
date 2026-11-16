<?php

namespace App\Services;

use App\Models\AppState;
use App\Models\CenterFamilySnapshot;
use App\Models\EcBoardEntry;
use App\Models\Family;
use App\Models\LocalAuth;
use App\Models\PendingCheckOut;
use App\Models\PendingReEnrollment;
use App\Models\ReturningFamilySnapshot;
use App\Models\ServerSnapshot;

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
            + PendingCheckOut::whereNull('synced_at')->count();
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
        ServerSnapshot::query()->delete();
    }

    /** Sent EC Board entries and families, except households a waiting entry still points to. */
    public function forgetSentRecords(): void
    {
        EcBoardEntry::whereNotNull('synced_at')->delete();

        $stillNeeded = EcBoardEntry::whereNull('synced_at')->whereNotNull('household_family_local_id')->pluck('household_family_local_id');
        Family::whereNotNull('synced_at')->whereNotIn('id', $stillNeeded)->get()->each->delete();
    }
}
