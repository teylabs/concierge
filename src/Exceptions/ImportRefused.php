<?php

namespace Tey\Concierge\Exceptions;

use RuntimeException;

/**
 * concierge:import-members could not plan the import; nothing was written.
 */
class ImportRefused extends RuntimeException
{
    public function __construct(string $message, public readonly string $fix)
    {
        parent::__construct($message);
    }
}
