<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The "Last known" (server-synced) half of the sectoral/4Ps dual view --
 * see this table's own migration docblock for why this is a separate
 * model from EvacuationCenterQuickCount (the "Pending" local-edit half).
 */
class EvacuationCenterSectoralSnapshot extends Model
{
    protected $fillable = [
        'evacuation_center_id', 'evacuation_event_id', 'beneficiaries_4ps',
        'sectoral_groups', 'updated_by_name', 'server_updated_at',
    ];

    protected $casts = [
        'sectoral_groups' => 'array',
        'server_updated_at' => 'datetime',
    ];

    public function evacuationCenter(): BelongsTo
    {
        return $this->belongsTo(EvacuationCenter::class);
    }

    public function evacuationEvent(): BelongsTo
    {
        return $this->belongsTo(EvacuationEvent::class);
    }
}
