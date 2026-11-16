<?php

namespace App\Exceptions;

/**
 * A 401: the stored token is dead (revoked or cleared on the server). The
 * user must log in again; nothing about the request itself was wrong, so
 * queued records keep their place.
 */
class CentralApiAuthenticationException extends CentralApiException
{
    public function __construct(string $message = 'Your session has expired. Log in again.')
    {
        parent::__construct($message, self::UNAUTHENTICATED, 401);
    }
}
