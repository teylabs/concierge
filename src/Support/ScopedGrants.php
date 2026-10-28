<?php

namespace Tey\Concierge\Support;

use Illuminate\Auth\Access\Response;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;

/**
 * Who may issue a scoped invitation: someone the grant policy lets manage the
 * workspace, granting only scoped roles their own roles may grant
 * (`grantable_by`). Scoped roles are never workspace-wide grants, so the
 * policy's authorizeGrant() is not asked about them.
 */
final class ScopedGrants
{
    public static function authorize(GrantPolicy $policy, Member $actor, Workspace $workspace, GrantSet $grants): Response
    {
        $management = $policy->authorizeManagement($actor, $workspace);

        if ($management->denied()) {
            return $management;
        }

        $membership = app(Concierge::class)->membership($actor, $workspace);
        $grantable = $membership ? RankRules::grantableScoped(app(Concierge::class)->roleNames($membership)) : [];

        foreach ($grants->roles as $role) {
            if (! in_array($role, $grantable, true)) {
                return Response::deny("You cannot grant the [{$role}] role.", 'not_grantable');
            }
        }

        return Response::allow();
    }

    /** A scoped invitation needs at least one role, and only scoped ones. */
    public static function valid(GrantSet $grants): bool
    {
        return $grants->roles !== [] && array_diff($grants->roles, Roles::scoped()) === [];
    }
}
