<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Evacuee extends Model
{
    protected $fillable = [
        'family_id', 'first_name', 'middle_name', 'last_name', 'suffix',
        'sex', 'date_of_birth', 'civil_status', 'contact_number',
        'is_pwd', 'pwd_type', 'is_pregnant', 'is_lactating', 'is_solo_parent',
        'is_indigenous_person', 'is_4ps_beneficiary', 'is_head_of_family',
        'remote_id', 'synced_at',
    ];
    protected $casts = [
        'date_of_birth' => 'date',
        'is_pwd' => 'boolean',
        'is_pregnant' => 'boolean',
        'is_lactating' => 'boolean',
        'is_solo_parent' => 'boolean',
        'is_indigenous_person' => 'boolean',
        'is_4ps_beneficiary' => 'boolean',
        'is_head_of_family' => 'boolean',
        'synced_at' => 'datetime',
    ];

    protected $appends = ['full_name'];

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function getFullNameAttribute(): string
    {
        // Filters out blank parts before joining, rather than a plain
        // trim() on the interpolated string -- that previously left a
        // double space wherever middle_name/suffix was null/empty (e.g.
        // "Rosa  Santos"), a pre-existing cosmetic bug this app's own
        // full registration flow could already trigger, now also visible
        // on every EC-Board-created household (see EcBoardEntryController
        // ::createNewHousehold(), which never sets a middle_name/suffix).
        return collect([$this->first_name, $this->middle_name, $this->last_name, $this->suffix])
            ->filter(fn ($part) => filled($part))
            ->implode(' ');
    }
}
