<?php

namespace Tey\Concierge\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** An app-wide invitation was issued (concierge.mode = 'app'). */
class UserInvited implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $invitationId,
        public ?int $actorId,
    ) {}
}
