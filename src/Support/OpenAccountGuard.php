<?php

namespace Tey\Concierge\Support;

use Illuminate\Auth\Access\Response;
use Tey\Concierge\Contracts\AccountGuard;
use Tey\Concierge\Contracts\Member;

/**
 * The default AccountGuard: no account is protected beyond the workspace rules.
 */
class OpenAccountGuard implements AccountGuard
{
    public function authorize(string $operation, Member $actor, Member $target): Response
    {
        return Response::allow();
    }
}
