<?php

namespace Tey\Concierge\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A user's app-wide roles were replaced. */
class UserRolesChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $userId,
        /** @var list<string> */
        public array $roles,
        public ?int $actorId,
    ) {}
}
