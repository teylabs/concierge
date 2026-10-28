<?php

namespace Tey\Concierge\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A scoped invitation was accepted: the membership now reaches the record.
 */
class ScopeAccepted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $workspaceId,
        public int $membershipId,
        public int $membershipScopeId,
        public string $scopeType,
        public string $scopeId,
        public int $userId,
        public int $invitationId,
    ) {}
}
