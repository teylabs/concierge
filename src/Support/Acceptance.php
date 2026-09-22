<?php

namespace Tey\Concierge\Support;

use Tey\Concierge\Enums\AcceptanceOutcome;
use Tey\Concierge\Models\Membership;

final readonly class Acceptance
{
    public function __construct(
        public Membership $membership,
        public AcceptanceOutcome $outcome,
    ) {}
}
