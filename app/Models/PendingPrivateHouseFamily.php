<?php

namespace App\Models;

use App\Models\Concerns\HasClientRef;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A family in a private house, added on this device and waiting to be sent. */
class PendingPrivateHouseFamily extends Model
{
    use HasClientRef;

    protected $fillable = [
        'client_ref', 'evacuation_event_id', 'barangay_remote_id', 'head', 'members', 'host_name', 'host_address',
        'host_purok_remote_id', 'is_4ps_beneficiary', 'remote_family_id', 'synced_at', 'sync_error', 'sync_state',
    ];

    protected $casts = [
        // Encrypted at rest with this computer's key (docs/local-data-protection.md).
        'head' => 'encrypted:array', 'members' => 'encrypted:array', 'host_name' => 'encrypted', 'host_address' => 'encrypted',
        'is_4ps_beneficiary' => 'boolean', 'synced_at' => 'datetime',
    ];

    public function evacuationEvent(): BelongsTo
    {
        return $this->belongsTo(EvacuationEvent::class);
    }

    public function isSynced(): bool
    {
        return $this->synced_at !== null;
    }

    /** "Rosa Santos", the head's name, as the server labels the family. */
    public function label(): string
    {
        return trim(($this->head['first_name'] ?? '').' '.($this->head['last_name'] ?? ''));
    }

    public function personCount(): int
    {
        return 1 + count($this->members ?? []);
    }

    /** The body of POST /barangays/{id}/private-house-families (without client_ref). */
    public function toSyncPayload(): array
    {
        return array_filter([
            'evacuation_event_id' => $this->evacuationEvent->remote_id,
            'head' => $this->head,
            'members' => array_values($this->members ?? []),
            'host_name' => $this->host_name,
            'host_address' => $this->host_address,
            'host_purok_id' => $this->host_purok_remote_id,
            'is_4ps_beneficiary' => $this->is_4ps_beneficiary,
        ], fn ($v) => $v !== null);
    }
}
