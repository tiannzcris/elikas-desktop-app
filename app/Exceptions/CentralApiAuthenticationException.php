<?php

namespace App\Exceptions;

/**
 * Thrown specifically when the central server rejects a request with a 401
 * -- meaning the stored Sanctum token is dead (revoked, or the row it
 * pointed at was cleared server-side), not that anything about the request
 * itself was wrong. Kept as its own type (rather than folding this into the
 * generic \RuntimeException every other failure here uses) so callers can
 * distinguish "your session is gone, log in again" from an ordinary 422
 * validation failure or a ConnectionException -- those need very different
 * handling and messaging, and string-matching on response text to tell them
 * apart would be fragile.
 */
class CentralApiAuthenticationException extends \RuntimeException
{
}
