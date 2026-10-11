<?php

namespace Tey\Concierge\Enums;

enum AccountStatus: string
{
    case Active = 'active';
    case Deactivated = 'deactivated';
}
