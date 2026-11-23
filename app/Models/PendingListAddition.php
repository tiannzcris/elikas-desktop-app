<?php

namespace App\Models;

use App\Models\Concerns\HasClientRef;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A family added to the families-to-evacuate list on this computer, waiting
 * to be sent: POST /families-to-evacuate with its client_ref (a retry lists
 * the family once). A new family, or a past event's family by source_family_id.
 */
class PendingListAddition extends Model
{
    use HasClientRef;

    protected $fillable = [
        'client_ref', 'evacuation_event_id', 'purok_remote_id', 'source_family_id', 'head_first_name', 'head_last_name',
        'head_sex', 'members_count', 'contact_number', 'note', 'label', 'listed_family_remote_id', 'synced_at', 'sync_error', 'sync_state',
    ];

    // Encrypted at rest with this computer's key (docs/local-data-protection.md).
    protected $casts = [
        'synced_at' => 'datetime', 'members_count' => 'integer',
        'head_first_name' => 'encrypted', 'head_last_name' => 'encrypted', 'contact_number' => 'encrypted', 'note' => 'encrypted', 'label' => 'encrypted',
    ];

    public function evacuationEvent(): BelongsTo
    {
        return $this->belongsTo(EvacuationEvent::class);
    }

    public function toSyncPayload(): array
    {
        $fields = $this->source_family_id
            ? ['source_family_id' => $this->source_family_id]
            : ['head_first_name' => $this->head_first_name, 'head_last_name' => $this->head_last_name, 'head_sex' => $this->head_sex,
                'members_count' => $this->members_count, 'contact_number' => $this->contact_number, 'note' => $this->note];

        return array_filter(['evacuation_event_id' => $this->evacuationEvent->remote_id, 'purok_id' => $this->purok_remote_id]
            + $fields + ['client_ref' => $this->client_ref], fn ($v) => $v !== null && $v !== '');
    }
}
