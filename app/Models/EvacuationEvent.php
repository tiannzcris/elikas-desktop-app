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

    /** The event the user is working on (every event-based screen uses it). */
    public static function working(): ?self
    {
        return app(\App\Services\SelectedEvent::class)->get();
    }

    /** Whether any event is open (active or monitored). Only an active one takes arrivals. */
    public static function anyOpen(): bool
    {
        return static::where('status', '!=', 'closed')->exists();
    }

    protected $fillable = ['remote_id', 'name', 'event_type', 'status', 'is_demo', 'start_date'];

    protected $casts = ['is_demo' => 'boolean', 'start_date' => 'date'];

    /**
     * The name as shown in a select. A practice event (is_demo) is named and
     * shown like a real one; is_demo is for internal logic only.
     */
    public function optionLabel(): string
    {
        return $this->name;
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /** Being monitored: a threat is announced, the evacuation has not started. */
    public function isMonitoring(): bool
    {
        return $this->status === 'monitoring';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * A validation rule for an evacuation_event_id that people are being
     * added to: refused while it is monitored or once it is closed, in the
     * server's words.
     */
    public static function openForArrivalsRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $event = static::find($value);
            if ($event?->status === 'closed') {
                $fail(static::anyOpen() ? static::CLOSED_TO_ARRIVALS : static::NO_OPEN_EVENT);
            } elseif ($event?->status === 'monitoring') {
                // Registering waits for CSWD's Start evacuation (Nov 22).
                $fail(\App\Support\EventLock::monitoringMessage($event->name, \App\Support\EventLock::REGISTERING));
            }
        };
    }
}
