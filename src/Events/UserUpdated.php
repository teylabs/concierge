<?php

namespace Tey\Concierge\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A user's profile fields were changed by someone managing them. */
class UserUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $userId,
        public ?int $actorId,
        /** @var array<string, array{old: string|null, new: string}> only the fields that changed, keyed name or email */
        public array $changes,
    ) {}
}
