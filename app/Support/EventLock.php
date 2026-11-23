<?php

namespace App\Support;

use App\Exceptions\CentralApiException;
use App\Models\EvacuationEvent;

/**
 * What an event's state holds back, in the server's own words
 * (docs/backend/apps-api-2026-11-22.md 1, "Locks"). The server refuses
 * with a 422 whose message is one of these; this device says the same
 * before asking, and shows a refusal of this kind with the one lock design
 * (<x-lock-notice>), never as an error.
 */
final class EventLock
{
    public const CLOSED = 'closed';

    public const MONITORING = 'monitoring';

    public const NO_OPEN_EVENT = 'no_open_event';

    /** The <What> of the server's monitoring message, per action. */
    public const REGISTERING = 'Registering evacuees';

    public const RETURNING = 'Registering returning families';

    public const PRIVATE_HOUSES = 'Registering families in private houses';

    public const CHECK_IN = 'Checking in listed families';

    public const RELIEF = 'Handing out relief boxes';

    public const TRANSFERS = 'Transfers';

    public const FINAL_COUNTS = 'Final counts';

    public const EC_BOARDS = 'EC Information Boards';

    /** "Typhoon Drill" is being monitored. Transfers opens when CSWD starts the evacuation. */
    public static function monitoringMessage(string $eventName, string $what): string
    {
        return "\"{$eventName}\" is being monitored. {$what} opens when CSWD starts the evacuation.";
    }

    public static function closedMessage(string $eventName): string
    {
        return "\"{$eventName}\" is closed. A closed event is read-only.";
    }

    /**
     * Why this action is locked for this event right now, or null when it
     * is allowed. No event at all reads as "No open event yet".
     */
    public static function reason(?EvacuationEvent $event, string $what): ?string
    {
        return match (true) {
            $event === null => EvacuationEvent::NO_OPEN_EVENT,
            $event->status === 'closed' => self::closedMessage($event->name),
            $event->status === 'monitoring' => self::monitoringMessage($event->name, $what),
            default => null,
        };
    }

    /**
     * Refuses, in the server's words, when this action is locked for the
     * event (closed, monitored, or none open). A closed event keeps the
     * server's arrivals wording for registrations.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function guard(?EvacuationEvent $event, string $what): void
    {
        $reason = self::reason($event, $what);
        if ($reason === null) {
            return;
        }
        if ($event?->status === 'closed' && in_array($what, [self::REGISTERING, self::RETURNING, self::PRIVATE_HOUSES], true)) {
            $reason = EvacuationEvent::anyOpen() ? EvacuationEvent::CLOSED_TO_ARRIVALS : EvacuationEvent::NO_OPEN_EVENT;
        }

        throw \Illuminate\Validation\ValidationException::withMessages(['evacuation_event_id' => $reason]);
    }

    /** Which lock a message is (closed, monitoring, no open event), or null for any other message. */
    public static function kindOf(?string $message): ?string
    {
        $message = (string) $message;

        return match (true) {
            $message === '' => null,
            str_contains($message, 'A closed event is read-only.'),
            str_starts_with($message, EvacuationEvent::CLOSED_TO_ARRIVALS) => self::CLOSED,
            str_starts_with($message, EvacuationEvent::NO_OPEN_EVENT) => self::NO_OPEN_EVENT,
            (bool) preg_match('/is being monitored\. .+ opens when CSWD starts the evacuation\./', $message) => self::MONITORING,
            default => null,
        };
    }

    public static function isLockMessage(?string $message): bool
    {
        return self::kindOf($message) !== null;
    }

    /** A 422 refusal because of the event's state. */
    public static function fromException(CentralApiException $e): ?string
    {
        return $e->kind === CentralApiException::INVALID ? self::kindOf($e->getMessage()) : null;
    }
}
