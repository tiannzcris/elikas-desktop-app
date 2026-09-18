<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvacuationCenterQuickCountSectoralGroup extends Model
{
    protected $fillable = ['evacuation_center_quick_count_id', 'sectoral_group', 'male_count', 'female_count'];

    public function quickCount(): BelongsTo
    {
        return $this->belongsTo(EvacuationCenterQuickCount::class, 'evacuation_center_quick_count_id');
    }
}
