<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * For one current event: the past events and the families of the
 * official's barangay that can be re-enrolled from each, as the server
 * last listed them (GET .../returning-families).
 */
class ReturningFamilySnapshot extends Model
{
    protected $fillable = ['evacuation_event_id', 'past_events', 'families_by_event', 'fetched_at'];

    // Encrypted at rest with this computer's key (docs/local-data-protection.md).
    protected $casts = ['past_events' => 'array', 'families_by_event' => 'encrypted:array', 'fetched_at' => 'datetime'];

    /** @return list<array> the families of one past event */
    public function familiesOf(int $sourceEventRemoteId): array
    {
        return $this->families_by_event[(string) $sourceEventRemoteId] ?? [];
    }
}
