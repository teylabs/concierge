<?php

namespace Tey\Concierge\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class MemberRolesChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $workspaceId,
        public int $membershipId,
        /** @var list<string> */
        public array $roles,
        public ?int $actorId,
    ) {}
}
