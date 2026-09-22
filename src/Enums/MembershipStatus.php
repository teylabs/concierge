<?php

namespace Tey\Concierge\Enums;

enum MembershipStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Removed = 'removed';
}
