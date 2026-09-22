<?php

namespace Tey\Concierge\Enums;

enum AcceptanceOutcome: string
{
    case Joined = 'joined';
    case AlreadyMember = 'already_member';
}
