<?php

namespace Tey\Concierge\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class InvitationRevoked implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public ?int $workspaceId,
        public int $invitationId,
        public ?int $actorId,
    ) {}
}
