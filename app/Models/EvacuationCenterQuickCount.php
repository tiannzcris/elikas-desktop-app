<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvacuationCenterQuickCount extends Model
{
    /**
     * Confirmed against the real central API: matches
     * EvacuationCenterQuickCount::SECTORAL_GROUPS on the backend exactly
     * (elikas-backend/app/Models/EvacuationCenterQuickCount.php), both the
     * enum values and this order (the EC Information Board template's own
     * presentation order). Labels added here purely for this device's
     * display -- the backend only stores/returns the bare key.
     */
    public const SECTORAL_GROUPS = [
        'pwd' => 'Persons with disability (PWD)',
        'child_headed_family' => 'Child-headed family',
        'single_headed_family' => 'Single-headed family',
        'solo_parent' => 'Solo parent',
        'pregnant_women' => 'Pregnant women',
        'lactating_mothers' => 'Lactating mothers',
        'four_ps_beneficiary' => '4Ps beneficiary',
        'indigenous_peoples' => 'Indigenous peoples',
    ];

    protected $fillable = [
        'evacuation_center_id', 'evacuation_event_id', 'beneficiaries_4ps', 'synced_at', 'sync_error',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
    ];

    public function evacuationCenter(): BelongsTo
    {
        return $this->belongsTo(EvacuationCenter::class);
    }

    public function evacuationEvent(): BelongsTo
    {
        return $this->belongsTo(EvacuationEvent::class);
    }

    public function sectoralGroups(): HasMany
    {
        return $this->hasMany(EvacuationCenterQuickCountSectoralGroup::class);
    }

    public function isSynced(): bool
    {
        return $this->synced_at !== null;
    }

    /**
     * Builds the payload for the central server's real quick-count save
     * endpoint (confirmed against elikas-backend's
     * EvacuationCenterController::updateQuickCount()) -- always sends all
     * 8 sectoral groups, zero-filled for any not yet reported locally,
     * matching exactly how the web dashboard's own EC Board form always
     * submits the full table rather than just changed rows.
     */
    public function toSyncPayload(): array
    {
        $groups = $this->sectoralGroups->keyBy('sectoral_group');

        return [
            'evacuation_event_id' => $this->evacuationEvent->remote_id,
            'beneficiaries_4ps' => $this->beneficiaries_4ps,
            'sectoral_groups' => collect(self::SECTORAL_GROUPS)->keys()->map(fn ($key) => [
                'sectoral_group' => $key,
                'male_count' => (int) ($groups[$key]->male_count ?? 0),
                'female_count' => (int) ($groups[$key]->female_count ?? 0),
            ])->values()->all(),
        ];
    }
}
