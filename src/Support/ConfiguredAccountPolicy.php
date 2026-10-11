<?php

namespace Tey\Concierge\Support;

use Illuminate\Auth\Access\Response;
use Tey\Concierge\Contracts\AccountGuard;
use Tey\Concierge\Contracts\AccountPolicy;
use Tey\Concierge\Contracts\Member;

/**
 * The default AccountPolicy: the configured rank rules (grantable_by,
 * managers, role order) plus the app-wide ownership rules.
 *
 *  - Owner is the top role and several owners are allowed.
 *  - Nobody manages their own account.
 *  - Equal rank is denied, except owner to owner.
 *  - The last active owner cannot be removed or demoted. Owners the
 *    AccountGuard shields from management (a platform operator) are not
 *    counted, so an operator can never be left as the only owner.
 *  - The AccountGuard's veto on the target is honoured.
 */
class ConfiguredAccountPolicy implements AccountPolicy
{
    public function __construct(protected AppScope $scope, protected AccountGuard $guard) {}

    public function grantableRoles(Member $actor): array
    {
        if (! $this->scope->account($actor)->isActive()) {
            return [];
        }

        return RankRules::grantable($this->scope->roleNames($actor));
    }

    public function authorizeManagement(Member $actor, ?Member $target = null): Response
    {
        if (! $this->scope->account($actor)->isActive()) {
            return Response::deny('Your account is not active.', 'not_member');
        }

        $held = $this->scope->roleNames($actor);

        if (! RankRules::manages($held)) {
            return Response::deny('You cannot manage users.', 'not_manager');
        }

        if (! $target) {
            return Response::allow();
        }

        if ((int) $target->getKey() === (int) $actor->getKey()) {
            return Response::deny('You cannot manage your own account.', 'self');
        }

        $guard = $this->guard->authorize(AccountGuard::MANAGE_ACCOUNT, $actor, $target);

        if ($guard->denied()) {
            return $guard;
        }

        $targetRoles = $this->scope->roleNames($target);
        $bothOwners = in_array(Roles::owner(), $held, true) && in_array(Roles::owner(), $targetRoles, true);

        if (! $bothOwners && ! RankRules::outranks($held, $targetRoles)) {
            return Response::deny('You cannot manage a user of equal or higher rank.', 'rank');
        }

        if ($bothOwners && $this->isLastOwner($actor, $target)) {
            return Response::deny('The last owner cannot be removed or demoted.', 'last_owner');
        }

        return Response::allow();
    }

    public function authorizeGrant(Member $actor, GrantSet $grants, ?Member $target = null): Response
    {
        $management = $this->authorizeManagement($actor, $target);

        if ($management->denied()) {
            return $management;
        }

        $grantable = $this->grantableRoles($actor);
        $owner = Roles::owner();

        foreach ($grants->roles as $role) {
            // Owner to owner: an owner may hand the owner role on, and keep or drop it on another owner.
            if ($role === $owner && $target && in_array($owner, $this->scope->roleNames($actor), true)) {
                continue;
            }

            if (! in_array($role, $grantable, true)) {
                return Response::deny("You cannot grant the [{$role}] role.", 'not_grantable');
            }
        }

        return Response::allow();
    }

    /**
     * True when no other active owner the actor could manage would remain:
     * owners shielded by the guard do not count.
     */
    protected function isLastOwner(Member $actor, Member $target): bool
    {
        foreach ($this->scope->owners() as $owner) {
            if ((int) $owner->getKey() === (int) $target->getKey()) {
                continue;
            }

            if ($this->scope->account($owner)->isActive()
                && $this->guard->authorize(AccountGuard::MANAGE_ACCOUNT, $actor, $owner)->allowed()) {
                return false;
            }
        }

        return true;
    }
}
