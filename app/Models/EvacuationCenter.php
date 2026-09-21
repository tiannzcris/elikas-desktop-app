<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvacuationCenter extends Model
{
    protected $fillable = ['remote_id', 'barangay_remote_id', 'name', 'status'];

    /**
     * Keyed off barangay_remote_id -> barangays.remote_id, not the usual
     * local-id foreign key -- evacuation_centers is a read-only cache of
     * central records, so it stores the barangay it belongs to by the
     * SAME remote id every other cross-reference in this cache table
     * uses (see FamilyController::centerSummary()'s own note on why this
     * matters: a center's OWN barangay can differ from the barangay
     * whose families are currently staying there).
     */
    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'barangay_remote_id', 'remote_id');
    }
}
