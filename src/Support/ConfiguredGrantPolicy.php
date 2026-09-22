<?php

namespace Tey\Concierge\Support;

use Illuminate\Auth\Access\Response;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Models\Membership;

class ConfiguredGrantPolicy implements GrantPolicy
{
    public function __construct(protected Concierge $concierge) {}

    public function grantableRoles(Member $actor, Workspace $workspace): array
    {
        $membership = $this->concierge->membership($actor, $workspace);

        if (! $membership?->isActive()) {
            return [];
        }

        $held = $this->concierge->roleNames($membership);

        return array_values(array_filter(
            Roles::configured(),
            fn (string $role) => $role !== Roles::owner()
                && array_intersect(config("concierge.roles.{$role}.grantable_by", []), $held) !== [],
        ));
    }

    public function authorizeManagement(Member $actor, Workspace $workspace, ?Membership $target = null): Response
    {
        $membership = $this->concierge->membership($actor, $workspace);

        if (! $membership?->isActive()) {
            return Response::deny('You are not an active member of this workspace.', 'not_member');
        }

        $held = $this->concierge->roleNames($membership);

        if (array_intersect(config('concierge.managers', []), $held) === []) {
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

        if ($this->concierge->isOwner($target)) {
            return Response::deny('The workspace owner cannot be managed.', 'owner_protected');
        }

        $targetRank = Roles::highestRank($this->concierge->roleNames($target));
        $actorRank = Roles::highestRank($held);

        if ($targetRank !== null && ($actorRank === null || $targetRank <= $actorRank)) {
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
}
