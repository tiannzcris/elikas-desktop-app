<?php

namespace App\Models;

use App\Models\Concerns\HasClientRef;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One relief box given to one family on this computer, waiting to be sent:
 * POST /box-relief/rounds/{round}/handouts {family_id, client_ref}. The
 * server keeps the first answer for a client_ref, so a retry never gives a
 * second box.
 */
class PendingHandout extends Model
{
    use HasClientRef;

    protected $fillable = [
        'client_ref', 'evacuation_event_id', 'round_remote_id', 'round_number', 'family_remote_id', 'family_label',
        'higher_priority_waiting', 'handout_remote_id', 'synced_at', 'sync_error', 'sync_state',
    ];

    // Encrypted at rest with this computer's key (docs/local-data-protection.md).
    protected $casts = ['synced_at' => 'datetime', 'family_label' => 'encrypted', 'higher_priority_waiting' => 'integer'];

    public function evacuationEvent(): BelongsTo
    {
        return $this->belongsTo(EvacuationEvent::class);
    }
}
