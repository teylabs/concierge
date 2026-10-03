<?php

namespace Tey\Concierge\Enums;

enum TenantAcceptanceOutcome: string
{
    case Provisioned = 'provisioned';
    case AlreadyProvisioned = 'already_provisioned';
}
