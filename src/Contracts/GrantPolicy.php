<?php

namespace Tey\Concierge\Contracts;

use Illuminate\Auth\Access\Response;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Support\GrantSet;

interface GrantPolicy
{
    /** @return list<string> */
    public function grantableRoles(Member $actor, Workspace $workspace): array;

    public function authorizeManagement(Member $actor, Workspace $workspace, ?Membership $target = null): Response;

    public function authorizeGrant(Member $actor, Workspace $workspace, GrantSet $grants, ?Membership $target = null): Response;
}
