<?php

namespace Tey\Concierge\Support;

use Tey\Concierge\Enums\InvitationState;
use Tey\Concierge\Models\TenantInvitation;

final readonly class TenantInvitationInspection
{
    public function __construct(
        public InvitationState $state,
        public ?TenantInvitation $invitation = null,
    ) {}
}
