<?php

namespace Tey\Concierge\Contracts;

use Illuminate\Auth\Access\Response;
use Tey\Concierge\Support\GrantSet;

/**
 * Who may manage whom in app-wide mode, where roles belong to the whole app
 * and the roster is the users table. The counterpart of GrantPolicy, which
 * answers the same questions for one workspace.
 */
interface AccountPolicy
{
    /** @return list<string> */
    public function grantableRoles(Member $actor): array;

    public function authorizeManagement(Member $actor, ?Member $target = null): Response;

    public function authorizeGrant(Member $actor, GrantSet $grants, ?Member $target = null): Response;
}
