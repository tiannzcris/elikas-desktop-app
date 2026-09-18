<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EcBoardEntry extends Model
{
    /**
     * Confirmed against the real central API: matches
     * EvacuationCenterQuickCount::AGE_BRACKETS on the backend exactly
     * (elikas-backend/app/Models/EvacuationCenterQuickCount.php) -- both
     * the values (what's sent/stored) and this exact order (what the EC
     * Information Board template presents). This app's earlier 0-5/6-12/
     * 13-17/18-59/60+ taxonomy was an unconfirmed guess and never matched
     * the real backend; keep this the single place both the "Add Evacuee"
     * form and the breakdown display read from, so it can't drift from the
     * real enum again.
     */
    public const AGE_BRACKETS = [
        'infant' => 'Infant',
        'toddler' => 'Toddler',
        'preschooler' => 'Preschooler',
        'school_age' => 'School age',
        'teenage' => 'Teenage',
        'adult' => 'Adult',
        'senior_citizen' => 'Senior citizen',
    ];

    protected $fillable = [
        'evacuation_center_id', 'evacuation_event_id', 'sex', 'age_bracket',
        'household_family_local_id', 'existing_household_remote_id', 'new_household_head_name',
        'originated_household', 'remote_id', 'synced_at', 'sync_error',
    ];

    protected $casts = [
        'originated_household' => 'boolean',
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

    /**
     * The "existing household" this evacuee belongs to, when one was
     * picked instead of typing a new household head's name. Null for a
     * "new household" entry.
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Family::class, 'household_family_local_id');
    }

    public function isSynced(): bool
    {
        return $this->synced_at !== null;
    }

    /**
     * The name shown for this entry's household on the pending list --
     * either the linked existing family's head-of-family name, or the
     * freehand name typed for a brand new household. Mirrors how
     * families/index.blade.php reads $family->barangay->name etc: resolved
     * here once rather than duplicated across every view that lists entries.
     */
    public function householdLabel(): string
    {
        if ($this->new_household_head_name) {
            return $this->new_household_head_name;
        }

        $head = $this->household?->evacuees->firstWhere('is_head_of_family', true);

        return $head?->full_name ?? 'Unknown household';
    }

    /**
     * Builds the payload for the central server's real "Add Evacuee"
     * endpoint (confirmed against elikas-backend's
     * EvacuationCenterController::addEvacuee()) -- household_mode/
     * family_id/family_name/barangay_id are its exact expected field
     * names, not this app's earlier guessed household_family_id/
     * new_household_head_name shape.
     *
     * A "new household" entry must also carry barangay_id -- the real
     * endpoint requires it to create that brand-new Family record, and
     * there's nowhere else for it to come from locally except the
     * evacuee's own center: barangay_remote_id is already the central
     * server's own barangay id (evacuation_centers is a read-only cache of
     * central records, keyed by remote id throughout), so no local-to-
     * remote resolution step is needed here the way barangay_id elsewhere
     * in this app (e.g. Family::toSyncPayload()) requires.
     *
     * An "existing household" entry can only ever mean something on the
     * central server once that household's own family record has synced
     * there (only then does it have a remote_id to link against as
     * family_id). Throwing here for that case reuses the exact same
     * per-record RuntimeException-becomes-sync_error handling already in
     * FamilyController::sync() -- no new error path needed.
     */
    public function toSyncPayload(): array
    {
        // A household picked from the LIVE central list (see
        // EvacuationCenterController::refreshHouseholds()) has no local
        // Family row at all -- it's already known-synced on the server by
        // definition of having been fetched from there, so its remote id
        // is used directly, skipping the local-row synced-check entirely.
        if ($this->existing_household_remote_id) {
            return [
                'evacuation_event_id' => $this->evacuationEvent->remote_id,
                'sex' => $this->sex,
                'age_bracket' => $this->age_bracket,
                'household_mode' => 'existing',
                'family_id' => $this->existing_household_remote_id,
                'barangay_id' => null,
                'family_name' => null,
            ];
        }

        // This entry's own submission is what created household_family_
        // local_id's Family locally (see EcBoardEntryController::
        // createNewHousehold()) -- that Family is a created_via_ec_board
        // placeholder that NEVER goes through FamilyController::sync()'s
        // own registerFamily() loop (the real /families/register endpoint
        // requires date_of_birth/contact_number per member, which "Add
        // Evacuee" never collects -- see this migration's own docblock:
        // 2026_02_01_000014_add_ec_board_household_support). It syncs
        // ONLY through this entry's own addEvacuee() call instead, same
        // household_mode=new shape as before this Family existed locally
        // at all -- FamilyController::sync() captures the family id back
        // from THIS response and stamps it onto the local Family once
        // this succeeds. Checked BEFORE the generic "existing" branch
        // below, which would otherwise treat this exactly like a
        // genuinely pre-existing household and wait forever for a
        // registerFamily() sync that will never happen.
        if ($this->originated_household) {
            return [
                'evacuation_event_id' => $this->evacuationEvent->remote_id,
                'sex' => $this->sex,
                'age_bracket' => $this->age_bracket,
                'household_mode' => 'new',
                'family_id' => null,
                'barangay_id' => $this->evacuationCenter->barangay_remote_id,
                'family_name' => $this->household?->evacuees->firstWhere('is_head_of_family', true)?->full_name,
            ];
        }

        if ($this->household_family_local_id && ! $this->household?->isSynced()) {
            throw new \RuntimeException(
                'The selected household has not synced yet -- sync it first, then sync this entry again.'
            );
        }

        $isExistingHousehold = $this->household_family_local_id !== null;

        return [
            'evacuation_event_id' => $this->evacuationEvent->remote_id,
            'sex' => $this->sex,
            'age_bracket' => $this->age_bracket,
            'household_mode' => $isExistingHousehold ? 'existing' : 'new',
            'family_id' => $isExistingHousehold ? $this->household->remote_id : null,
            'barangay_id' => $isExistingHousehold ? null : $this->evacuationCenter->barangay_remote_id,
            'family_name' => $isExistingHousehold ? null : $this->new_household_head_name,
        ];
    }
}
