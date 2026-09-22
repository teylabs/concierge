<?php

namespace Tey\Concierge\Contracts;

use Tey\Concierge\Support\MembershipDirectory;

/**
 * Opt-in for grant policies that can answer page-rendering reads from a
 * per-call MembershipDirectory. Must return a new policy instance and never
 * mutate the one resolved from the container.
 */
interface BatchesMembershipReads
{
    public function usingDirectory(MembershipDirectory $directory): GrantPolicy;
}
