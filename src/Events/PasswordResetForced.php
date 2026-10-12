<?php

namespace Tey\Concierge\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class PasswordResetForced implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  int|null  $workspaceId  Null in app-wide mode.
     * @param  int|null  $membershipId  Null in app-wide mode.
     * @param  int|null  $sessionsEnded  Database session rows deleted, or null when the
     *                                   session driver is not `database` (see the action).
     */
    public function __construct(
        public ?int $workspaceId,
        public ?int $membershipId,
        public int $userId,
        public ?int $actorId,
        public bool $twoFactorReset,
        public ?int $sessionsEnded,
    ) {}
}
