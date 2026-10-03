<?php

namespace Tey\Concierge\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Carries ids only: never rely on an ambient tenant when handling it.
 */
class TenantInvited implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $invitationId, public ?int $actorId,
    ) {}
}
