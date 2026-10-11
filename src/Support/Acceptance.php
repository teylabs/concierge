<?php

namespace Tey\Concierge\Support;

use Tey\Concierge\Enums\AcceptanceOutcome;
use Tey\Concierge\Models\Account;
use Tey\Concierge\Models\Membership;

/**
 * Workspace mode fills membership; app-wide mode fills account.
 */
final readonly class Acceptance
{
    public function __construct(
        public ?Membership $membership,
        public AcceptanceOutcome $outcome,
        public ?Account $account = null,
    ) {}
}
