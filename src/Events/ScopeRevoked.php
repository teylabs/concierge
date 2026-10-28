<?php

namespace Tey\Concierge\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A membership no longer reaches the record. membershipRemoved is true when it
 * was a guest's last scope and the guest membership was removed with it.
 */
class ScopeRevoked implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $workspaceId,
        public int $membershipId,
        public int $membershipScopeId,
        public string $scopeType,
        public string $scopeId,
        public ?int $actorId,
        public bool $membershipRemoved,
    ) {}
}
