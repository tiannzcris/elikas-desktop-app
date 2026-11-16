<?php

namespace App\Exceptions;

/**
 * Any failed call to the central server, sorted by what the caller should
 * do about it (kind), so no caller has to read status codes or match on
 * message text:
 *
 *  - offline:          the server could not be reached; try again later.
 *  - unauthenticated:  401, the token is dead; log in again.
 *  - forbidden:        403, not allowed (another barangay, no permission,
 *                      or an inactive account).
 *  - not_found:        404, gone or not visible to this user.
 *  - conflict:         409, the server needs a decision (data() holds it).
 *  - invalid:          422, the server refused this record (errors()).
 *  - gone:             410, the endpoint was retired.
 *  - server:           anything else (5xx, unexpected).
 */
class CentralApiException extends \RuntimeException
{
    public const OFFLINE = 'offline';

    public const UNAUTHENTICATED = 'unauthenticated';

    public const FORBIDDEN = 'forbidden';

    public const NOT_FOUND = 'not_found';

    public const CONFLICT = 'conflict';

    public const INVALID = 'invalid';

    public const GONE = 'gone';

    public const SERVER = 'server';

    public function __construct(
        string $message,
        public readonly string $kind,
        public readonly ?int $status = null,
        private readonly array $errors = [],
        private readonly array $data = [],
    ) {
        parent::__construct($message, $status ?? 0);
    }

    public static function kindForStatus(int $status): string
    {
        return match (true) {
            $status === 401 => self::UNAUTHENTICATED,
            $status === 403 => self::FORBIDDEN,
            $status === 404 => self::NOT_FOUND,
            $status === 409 => self::CONFLICT,
            $status === 410 => self::GONE,
            $status === 422 => self::INVALID,
            default => self::SERVER,
        };
    }

    public function isOffline(): bool
    {
        return $this->kind === self::OFFLINE;
    }

    /** Field errors from a 422, as the server sent them. */
    public function errors(): array
    {
        return $this->errors;
    }

    /** The response's data block (a 409's possible duplicates, for one). */
    public function data(): array
    {
        return $this->data;
    }
}
