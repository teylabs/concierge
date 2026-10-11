<?php

namespace Tey\Concierge\Actions;

use Illuminate\Auth\Access\Response;
use Tey\Concierge\Concerns\Runnable;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Events\MembershipReinstated;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Support\ManagedMembership;

/**
 * Restores access with the roles the member already held; the actor must
 * still outrank those roles, so reinstatement cannot bypass grant policy.
 */
class ReinstateMembership
{
    use Runnable;

    public function __construct(protected GrantPolicy $policy) {}

    public function handle(Member $actor, Membership $membership): Membership
    {
        return ManagedMembership::mutate($membership, function (Workspace $workspace, Membership $membership) use ($actor) {
            $this->policy->authorizeManagement($actor, $workspace, $membership)->authorize();

            if ($membership->status === MembershipStatus::Active) {
                return $membership;
            }

            if ($membership->status === MembershipStatus::Removed) {
                Response::deny(__('Removed members must be invited again.'), 'not_member')->authorize();
            }

            $membership->forceFill([
                'status' => MembershipStatus::Active,
                'suspended_at' => null,
                'suspended_by_id' => null,
                'suspension_reason' => null,
            ])->save();

            MembershipReinstated::dispatch($workspace->getKey(), $membership->getKey(), $actor->getKey());

            return $membership;
        });
    }
}
