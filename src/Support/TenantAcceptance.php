<?php

namespace Tey\Concierge\Support;

use Illuminate\Database\Eloquent\Model;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Enums\TenantAcceptanceOutcome;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Models\TenantInvitation;

final readonly class TenantAcceptance
{
    /** @param  Workspace&Model  $workspace */
    public function __construct(
        public TenantInvitation $invitation,
        public Workspace $workspace,
        public Membership $membership,
        public TenantAcceptanceOutcome $outcome,
    ) {}
}
