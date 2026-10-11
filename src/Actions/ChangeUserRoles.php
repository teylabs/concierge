<?php

namespace Tey\Concierge\Actions;

use Tey\Concierge\Concerns\Runnable;
use Tey\Concierge\Contracts\AccountPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Events\UserRolesChanged;
use Tey\Concierge\Models\Account;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\GrantSet;
use Tey\Concierge\Support\ManagedAccount;
use Tey\Concierge\Support\Roles;

/**
 * App-wide mode's ChangeMemberRoles. Granting the owner role is how
 * ownership passes on: there is no TransferOwnership in app-wide mode.
 */
class ChangeUserRoles
{
    use Runnable;

    public function __construct(protected AccountPolicy $policy) {}

    public function handle(Member $actor, Member $user, GrantSet $grants): Account
    {
        ConciergeModels::assertAppWide('use ChangeMemberRoles');

        return ManagedAccount::mutate($actor, $user, function (Account $actorAccount, ?Account $account) use ($actor, $user, $grants) {
            $this->policy->authorizeGrant($actor, $grants, $user)->authorize();

            $user->syncRoles(Roles::resolve($grants->roles));

            UserRolesChanged::dispatch((int) $user->getKey(), $grants->roles, $actor->getKey());

            /** @var Account $account */
            return $account;
        });
    }
}
