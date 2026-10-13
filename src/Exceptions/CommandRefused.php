<?php

namespace Tey\Concierge\Exceptions;

use RuntimeException;

/**
 * A concierge command could not run; nothing was written.
 */
class CommandRefused extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }
}
