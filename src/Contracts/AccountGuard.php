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

    public function authorize(string $operation, Member $actor, Member $target): Response;
}
