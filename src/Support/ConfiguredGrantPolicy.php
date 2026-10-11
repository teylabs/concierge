<?php

namespace Tey\Concierge\Support;

use Illuminate\Auth\Access\Response;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\BatchesMembershipReads;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Models\Membership;

class ConfiguredGrantPolicy implements BatchesMembershipReads, GrantPolicy
{
    protected ?MembershipDirectory $directory = null;

    public function __construct(protected Concierge $concierge) {}

    public function usingDirectory(MembershipDirectory $directory): GrantPolicy
    {
        $policy = clone $this;
        $policy->directory = $directory;

        return $policy;
    }

    public function grantableRoles(Member $actor, Workspace $workspace): array
    {
        $membership = $this->membership($actor, $workspace);

        if (! $membership?->isActive()) {
            return [];
        }

        return RankRules::grantable($this->roleNames($workspace, $membership));
    }

    public function authorizeManagement(Member $actor, Workspace $workspace, ?Membership $target = null): Response
    {
        $membership = $this->membership($actor, $workspace);

        if (! $membership?->isActive()) {
            return Response::deny('You are not an active member of this workspace.', 'not_member');
        }

        $held = $this->roleNames($workspace, $membership);

        if (! RankRules::manages($held)) {
            return Response::deny('You cannot manage members of this workspace.', 'not_manager');
        }

        if (! $target) {
            return Response::allow();
        }

        if ((int) $target->workspace_id !== (int) $workspace->getKey()) {
            return Response::deny('That membership belongs to another workspace.', 'not_member');
        }

        if ((int) $target->user_id === (int) $actor->getKey()) {
            return Response::deny('You cannot manage your own membership.', 'self');
        }

        if ($this->isOwner($workspace, $target)) {
            return Response::deny('The workspace owner cannot be managed.', 'owner_protected');
        }

        if (! RankRules::outranks($held, $this->roleNames($workspace, $target))) {
            return Response::deny('You cannot manage a member of equal or higher rank.', 'rank');
        }

        return Response::allow();
    }

    public function authorizeGrant(Member $actor, Workspace $workspace, GrantSet $grants, ?Membership $target = null): Response
    {
        $management = $this->authorizeManagement($actor, $workspace, $target);

        if ($management->denied()) {
            return $management;
        }

        $grantable = $this->grantableRoles($actor, $workspace);

        foreach ($grants->roles as $role) {
            if (! in_array($role, $grantable, true)) {
                return Response::deny("You cannot grant the [{$role}] role.", 'not_grantable');
            }
        }

        return Response::allow();
    }

    protected function membership(Member $actor, Workspace $workspace): ?Membership
    {
        return $this->directory?->covers($workspace)
            ? $this->directory->membershipOf($actor)
            : $this->concierge->membership($actor, $workspace);
    }

    /** @return list<string> */
    protected function roleNames(Workspace $workspace, Membership $membership): array
    {
        return $this->directory?->covers($workspace)
            ? $this->directory->roleNames($membership)
            : $this->concierge->roleNames($membership);
    }

    protected function isOwner(Workspace $workspace, Membership $membership): bool
    {
        return $this->directory?->covers($workspace)
            ? $this->directory->isOwner($membership)
            : $this->concierge->isOwner($membership);
    }
}
