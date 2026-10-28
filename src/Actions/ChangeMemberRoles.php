<?php

namespace Tey\Concierge\Actions;

use Illuminate\Auth\Access\Response;
use Tey\Concierge\Concerns\Runnable;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Events\MemberRolesChanged;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Support\GrantSet;
use Tey\Concierge\Support\ManagedMembership;
use Tey\Concierge\Support\Roles;

class ChangeMemberRoles
{
    use Runnable;

    public function __construct(protected GrantPolicy $policy, protected Concierge $concierge) {}

    public function handle(Member $actor, Membership $membership, GrantSet $grants): Membership
    {
        return ManagedMembership::mutate($membership, function (Workspace $workspace, Membership $membership) use ($actor, $grants) {
            $this->policy->authorizeGrant($actor, $workspace, $grants, $membership)->authorize();

            if ($membership->status === MembershipStatus::Removed) {
                Response::deny(__('That person is no longer a member.'), 'not_member')->authorize();
            }

            // A guest reaches only their scoped records; invite them to the workspace to give them roles here.
            if ($this->concierge->isScopedGuest($membership)) {
                Response::deny(__('A guest has no workspace roles; invite them to the workspace instead.'), 'guest')->authorize();
            }

            $user = $membership->user;

            $this->concierge->context()->run(
                $workspace,
                fn () => $user->syncRoles(Roles::resolve($grants->roles)),
                [$user],
            );

            MemberRolesChanged::dispatch($workspace->getKey(), $membership->getKey(), $grants->roles, $actor->getKey());

            return $membership;
        });
    }
}
