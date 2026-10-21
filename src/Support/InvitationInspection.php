<?php

namespace Tey\Concierge\Support;

use Tey\Concierge\Enums\InvitationState;
use Tey\Concierge\Models\Invitation;

final readonly class InvitationInspection
{
    public function __construct(
        public InvitationState $state,
        public ?Invitation $invitation = null,
        /** Ready for a signed-in account whose email is not the invited one (invitations.match_email false). */
        public bool $otherEmail = false,
    ) {}
}
