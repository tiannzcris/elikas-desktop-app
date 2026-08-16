<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Family extends Model
{
    protected $fillable = [
        'barangay_id', 'evacuation_event_id', 'evacuation_center_id',
        'displacement_type', 'is_4ps_beneficiary', 'home_address',
        'remote_id', 'synced_at', 'sync_error',
    ];

    protected $casts = [
        'is_4ps_beneficiary' => 'boolean',
        'synced_at' => 'datetime',
    ];

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public function evacuationEvent(): BelongsTo
    {
        return $this->belongsTo(EvacuationEvent::class);
    }

    public function evacuationCenter(): BelongsTo
    {
        return $this->belongsTo(EvacuationCenter::class);
    }

    public function evacuees(): HasMany
    {
        return $this->hasMany(Evacuee::class);
    }

    public function isSynced(): bool
    {
        return $this->synced_at !== null;
    }

    /**
     * Builds the exact JSON shape RegisterFamilyRequest expects on the
     * central server -- keeping this in one place means the sync service
     * never has to duplicate knowledge of that endpoint's payload shape.
     * Resolves this family's LOCAL foreign keys to the central server's
     * REMOTE ids via the cache tables' remote_id column, since the central
     * API has never heard of this device's local SQLite row ids.
     */
    public function toSyncPayload(): array
    {
        return [
            'evacuation_event_id' => $this->evacuationEvent->remote_id,
            'barangay_id' => $this->barangay->remote_id,
            'evacuation_center_id' => $this->evacuationCenter?->remote_id,
            'displacement_type' => $this->displacement_type,
            'is_4ps_beneficiary' => $this->is_4ps_beneficiary,
            'home_address' => $this->home_address,
            'members' => $this->evacuees->map(fn (Evacuee $e) => [
                'first_name' => $e->first_name,
                'middle_name' => $e->middle_name,
                'last_name' => $e->last_name,
                'suffix' => $e->suffix,
                'sex' => $e->sex,
                'date_of_birth' => $e->date_of_birth->format('Y-m-d'),
                'civil_status' => $e->civil_status,
                'contact_number' => $e->contact_number,
                'is_pwd' => (bool) $e->is_pwd,
                'pwd_type' => $e->pwd_type,
                'is_pregnant' => (bool) $e->is_pregnant,
                'is_lactating' => (bool) $e->is_lactating,
                'is_solo_parent' => (bool) $e->is_solo_parent,
                'is_indigenous_person' => (bool) $e->is_indigenous_person,
                'is_4ps_beneficiary' => (bool) $e->is_4ps_beneficiary,
                'is_head_of_family' => (bool) $e->is_head_of_family,
            ])->toArray(),
        ];
    }
}
