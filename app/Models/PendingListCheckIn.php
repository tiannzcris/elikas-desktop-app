<?php

namespace App\Models;

use App\Models\Concerns\HasClientRef;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A listed family checked in on this device (Families to evacuate), waiting
 * to be sent: POST /families-to-evacuate/{id}/check-in with its client_ref,
 * so a retry returns the first answer and never registers the family twice.
 */
class PendingListCheckIn extends Model
{
    use HasClientRef;

    protected $fillable = [
        'client_ref', 'evacuation_event_id', 'listed_family_remote_id', 'family_label', 'members_count',
        'evacuation_center_id', 'host_name', 'host_purok_remote_id', 'host_address',
        'registered_family_id', 'synced_at', 'sync_error', 'sync_state',
    ];

    // Encrypted at rest with this computer's key (docs/local-data-protection.md).
    protected $casts = ['synced_at' => 'datetime', 'members_count' => 'integer',
        'family_label' => 'encrypted', 'host_name' => 'encrypted', 'host_address' => 'encrypted'];

    public function evacuationEvent(): BelongsTo
    {
        return $this->belongsTo(EvacuationEvent::class);
    }

    public function evacuationCenter(): BelongsTo
    {
        return $this->belongsTo(EvacuationCenter::class);
    }

    public function isPrivateHouse(): bool
    {
        return $this->evacuation_center_id === null;
    }

    /** Where the family goes: the center's name, or "Private house (with <host>)". */
    public function destination(): string
    {
        return $this->isPrivateHouse() ? "Private house (with {$this->host_name})" : (string) $this->evacuationCenter?->name;
    }

    public function toSyncPayload(): array
    {
        return array_filter([
            'evacuation_center_id' => $this->evacuationCenter?->remote_id,
            'host_name' => $this->host_name,
            'host_purok_id' => $this->host_purok_remote_id,
            'host_address' => $this->host_address,
            'client_ref' => $this->client_ref,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
