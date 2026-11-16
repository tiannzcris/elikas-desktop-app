<?php

namespace App\Models;

use App\Services\CentralApiService;
use Illuminate\Database\Eloquent\Model;

/** The server's last list of families checked in at a center for an event. */
class CenterFamilySnapshot extends Model
{
    protected $fillable = ['evacuation_center_id', 'evacuation_event_id', 'families', 'fetched_at'];

    protected $casts = ['families' => 'array', 'fetched_at' => 'datetime'];

    public static function for(EvacuationCenter $center, EvacuationEvent $event): ?self
    {
        return static::where('evacuation_center_id', $center->id)->where('evacuation_event_id', $event->id)->first();
    }

    /** Fetches the list now and keeps it. Throws CentralApiException when it can't. */
    public static function fetch(CentralApiService $api, string $token, EvacuationCenter $center, EvacuationEvent $event): self
    {
        $families = $api->fetchFamiliesAtCenter($token, $center->remote_id, $event->remote_id);

        return static::updateOrCreate(
            ['evacuation_center_id' => $center->id, 'evacuation_event_id' => $event->id],
            ['families' => array_values($families), 'fetched_at' => now()],
        );
    }
}
