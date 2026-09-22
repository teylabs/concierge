<?php

namespace Tey\Concierge\Exceptions;

use RuntimeException;
use Tey\Concierge\Enums\InvitationState;

class InvitationRejected extends RuntimeException
{
    public function __construct(public readonly InvitationState $state)
    {
        parent::__construct("Invitation rejected: {$state->value}.");
    }
}
