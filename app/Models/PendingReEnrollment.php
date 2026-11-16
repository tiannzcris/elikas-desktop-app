<?php

namespace App\Models;

use App\Models\Concerns\HasClientRef;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A returning family ticked on this device, waiting to be sent. */
class PendingReEnrollment extends Model
{
    use HasClientRef;

    protected $fillable = [
        'client_ref', 'evacuation_center_id', 'evacuation_event_id', 'source_family_remote_id', 'family_name',
        'source_event_name', 'members', 'duplicate_decision', 'link_family_remote_id', 'possible_duplicates',
        'remote_family_id', 'synced_at', 'sync_error', 'sync_state',
    ];

    protected $casts = ['members' => 'array', 'possible_duplicates' => 'array', 'synced_at' => 'datetime'];

    public function evacuationCenter(): BelongsTo
    {
        return $this->belongsTo(EvacuationCenter::class);
    }

    public function evacuationEvent(): BelongsTo
    {
        return $this->belongsTo(EvacuationEvent::class);
    }

    public function isSynced(): bool
    {
        return $this->synced_at !== null;
    }

    /** The body of POST .../re-enroll (without client_ref). */
    public function toSyncPayload(): array
    {
        return array_filter([
            'evacuation_event_id' => $this->evacuationEvent->remote_id,
            'source_family_id' => $this->source_family_remote_id,
            'members' => collect($this->members)->map(fn ($m) => array_filter([
                'evacuee_id' => $m['evacuee_id'],
                'age_bracket' => $m['age_bracket'] ?? null,
                'is_pregnant' => $m['is_pregnant'] ?? null,
                'is_lactating' => $m['is_lactating'] ?? null,
            ], fn ($v) => $v !== null))->values()->all(),
            'duplicate_decision' => $this->duplicate_decision,
            'link_family_id' => $this->link_family_remote_id,
        ], fn ($v) => $v !== null);
    }
}
