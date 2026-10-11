<?php

namespace Tey\Concierge\Support;

use Tey\Concierge\Models\Membership;

/**
 * What a forced password reset did.
 */
final readonly class ForcedPasswordReset
{
    /**
     * @param  int|null  $sessionsEnded  Database session rows deleted, or null when the
     *                                   session driver is not `database`.
     */
    public function __construct(
        public Membership $membership,
        public bool $twoFactorReset,
        public ?int $sessionsEnded,
    ) {}
}
