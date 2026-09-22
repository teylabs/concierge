<?php

namespace Tey\Concierge\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class InvitationAccepted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $workspaceId,
        public int $invitationId,
        public int $membershipId,
        public int $userId,
        public string $outcome,
    ) {}
}
