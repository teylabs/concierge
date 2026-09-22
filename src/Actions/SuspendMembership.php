<?php

namespace Tey\Concierge\Actions;

use Illuminate\Auth\Access\Response;
use Lorisleiva\Actions\Concerns\AsAction;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Events\MembershipSuspended;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Support\ManagedMembership;

class SuspendMembership
{
    use AsAction;

    public function __construct(protected GrantPolicy $policy) {}

    public function handle(Member $actor, Membership $membership, ?string $reason = null): Membership
    {
        return ManagedMembership::mutate($membership, function (Workspace $workspace, Membership $membership) use ($actor, $reason) {
            $this->policy->authorizeManagement($actor, $workspace, $membership)->authorize();

            if ($membership->status === MembershipStatus::Suspended) {
                return $membership;
            }

            if ($membership->status === MembershipStatus::Removed) {
                Response::deny(__('That person is no longer a member.'), 'not_member')->authorize();
            }

            $membership->forceFill([
                'status' => MembershipStatus::Suspended,
                'suspended_at' => now(),
                'suspended_by_id' => $actor->getKey(),
                'suspension_reason' => $reason === null ? null : mb_substr($reason, 0, 500),
            ])->save();

            MembershipSuspended::dispatch($workspace->getKey(), $membership->getKey(), $actor->getKey());

            return $membership;
        });
    }
}
