<?php

namespace Tey\Concierge\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A user was deactivated: sign-in refused, sessions and remember-me ended. Roles and memberships are kept. */
class UserDeactivated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $userId,
        public ?int $actorId,
        public ?string $reason,
        public ?int $sessionsEnded,
    ) {}
}
