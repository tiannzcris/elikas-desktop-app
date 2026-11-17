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

    /** The server's words when no event is open (EventFlow::NO_OPEN_EVENT). */
    public const NO_OPEN_EVENT = 'No open event yet. CSWDO opens an event when a threat is announced.';

    /** Whether any event takes arrivals (active, or monitoring: the server switches it to active). */
    public static function anyOpen(): bool
    {
        return static::where('status', '!=', 'closed')->exists();
    }

    protected $fillable = ['remote_id', 'name', 'event_type', 'status', 'is_demo', 'start_date'];

    protected $casts = ['is_demo' => 'boolean', 'start_date' => 'date'];

    /** The name as shown in a select, where a badge cannot go. */
    public function optionLabel(): string
    {
        return $this->is_demo && ! str_starts_with($this->name, 'DEMO') ? "DEMO - {$this->name}" : $this->name;
    }

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
                $fail(static::anyOpen() ? static::CLOSED_TO_ARRIVALS : static::NO_OPEN_EVENT);
            }
        };
    }
}
