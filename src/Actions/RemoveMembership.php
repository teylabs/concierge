<?php

namespace Tey\Concierge\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\GrantPolicy;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Events\MembershipRemoved;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Support\ManagedMembership;

/**
 * Ends membership in one workspace only. The user row, authored content and
 * other workspaces' roles are untouched.
 */
class RemoveMembership
{
    use AsAction;

    public function __construct(protected GrantPolicy $policy, protected Concierge $concierge) {}

    public function handle(Member $actor, Membership $membership): Membership
    {
        return ManagedMembership::mutate($membership, function (Workspace $workspace, Membership $membership) use ($actor) {
            $this->policy->authorizeManagement($actor, $workspace, $membership)->authorize();

            if ($membership->status === MembershipStatus::Removed) {
                return $membership;
            }

            $user = $membership->user;

            $this->concierge->context()->run($workspace, function () use ($user) {
                $user->syncRoles([]);
                $user->syncPermissions([]);
            }, [$user]);

            $membership->forceFill([
                'status' => MembershipStatus::Removed,
                'removed_at' => now(),
                'removed_by_id' => $actor->getKey(),
            ])->save();

            MembershipRemoved::dispatch($workspace->getKey(), $membership->getKey(), $actor->getKey());

            return $membership;
        });
    }
}
