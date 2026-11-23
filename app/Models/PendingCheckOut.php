<?php

namespace App\Models;

use App\Models\Concerns\HasClientRef;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A whole family checked out on this device, waiting to be sent. */
class PendingCheckOut extends Model
{
    use HasClientRef;

    /** The server's departure reasons (EvacueeController::checkOut). */
    public const STATUSES = [
        'returned_home' => 'Returned home',
        'transferred' => 'Transferred',
        'other' => 'Other',
    ];

    protected $fillable = [
        'client_ref', 'evacuation_center_id', 'evacuation_event_id', 'family_remote_id', 'household_family_local_id',
        'family_label', 'status', 'people_checked_out', 'synced_at', 'sync_error', 'sync_state',
    ];

    // Encrypted at rest with this computer's key (docs/local-data-protection.md).
    protected $casts = ['synced_at' => 'datetime', 'family_label' => 'encrypted'];

    public function evacuationCenter(): BelongsTo
    {
        return $this->belongsTo(EvacuationCenter::class);
    }

    public function evacuationEvent(): BelongsTo
    {
        return $this->belongsTo(EvacuationEvent::class);
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Family::class, 'household_family_local_id');
    }

    public function isSynced(): bool
    {
        return $this->synced_at !== null;
    }

    /** The server id of the family: known at once, or once its household is sent. */
    public function familyRemoteId(): ?int
    {
        return $this->family_remote_id ?? $this->household?->remote_id;
    }
}
