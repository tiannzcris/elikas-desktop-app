<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvacuationEvent extends Model
{
    /**
     * The central server's answer to adding anyone to a closed event (Add
     * Evacuee, Register Family, Add member), given here too when this
     * device already knows the event is closed.
     */
    public const CLOSED_TO_ARRIVALS = 'This event is closed. Evacuees can no longer be added to it.';

    protected $fillable = ['remote_id', 'name', 'event_type', 'status'];

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /**
     * A validation rule for an evacuation_event_id that people are being
     * added to: refused once the event is closed, in the server's words.
     */
    public static function openForArrivalsRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (static::whereKey($value)->value('status') === 'closed') {
                $fail(static::CLOSED_TO_ARRIVALS);
            }
        };
    }
}
