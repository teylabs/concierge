<?php

namespace Tey\Concierge\Contracts;

use Illuminate\Auth\Access\Response;

/**
 * A host-level veto over operations on an account, checked after concierge's
 * own workspace rules pass. Bind an implementation to protect accounts that no
 * manager may act on (for example a platform operator account). The default
 * implementation allows everything.
 */
interface AccountGuard
{
    public const FORCE_PASSWORD_RESET = 'force-password-reset';

    /** App-wide mode: changing a user's roles or removing them. The AccountPolicy asks before its rank rules. */
    public const MANAGE_ACCOUNT = 'manage-account';

    public function authorize(string $operation, Member $actor, Member $target): Response;
}
