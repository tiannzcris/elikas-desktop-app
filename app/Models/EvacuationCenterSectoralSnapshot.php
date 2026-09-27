<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The central server's last-known EC Information Board for one
 * center+event, apart from the age/sex rows (evacuation_center_breakdowns):
 * the header's family/person counts, 4Ps families and the sectoral groups
 * -- a read-only display cache, refreshed on demand (see
 * EvacuationCenterController::refreshBoard()). fetched_at is when that
 * data last arrived from the server: the board's "As of". Nothing on this
 * device writes these figures: every one is counted live by the server.
 */
class EvacuationCenterSectoralSnapshot extends Model
{
    /**
     * The central server's eight sectoral groups, in the EC Information
     * Board template's own order (its EvacuationCenterQuickCount::
     * SECTORAL_GROUPS), with this device's display labels. Every one is
     * live-counted by the server -- none is typed in anywhere.
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

    /**
     * The two groups that describe a whole household, each with the Family
     * method it's counted from -- same as the server's own
     * HOUSEHOLD_SECTORAL_GROUPS. The other six come from per-person flags
     * (EcBoardEntry::SECTORAL_FLAGS).
     */
    public const HOUSEHOLD_SECTORAL_GROUPS = [
        'child_headed_family' => 'isChildHeaded',
        'single_headed_family' => 'isSingleHeaded',
    ];

    protected $fillable = [
        'evacuation_center_id', 'evacuation_event_id', 'beneficiaries_4ps',
        'families_cumulative', 'families_now', 'persons_cumulative', 'persons_now',
        'sectoral_groups', 'updated_by_name', 'server_updated_at', 'fetched_at',
    ];

    protected $casts = [
        'sectoral_groups' => 'array',
        'server_updated_at' => 'datetime',
        'fetched_at' => 'datetime',
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
