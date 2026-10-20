<?php

namespace Tey\Concierge\Actions;

use Illuminate\Auth\Access\Response;
use Tey\Concierge\Concerns\Runnable;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Enums\Mode;
use Tey\Concierge\Events\MembershipRemoved;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Support\ManagedMembership;

/**
 * A member ends their own membership of one workspace. The owner cannot: ownership
 * has to be transferred first, so a workspace is never left without one. Like
 * RemoveMembership, only that workspace is touched: the user row, authored content
 * and other workspaces' roles stay. The membership is marked removed by the user
 * themselves, and a new invitation can bring them back.
 */
class LeaveWorkspace
{
    use Runnable;

    public function __construct(protected Concierge $concierge) {}

    public function handle(Member $user, Workspace $workspace): Membership
    {
        if ($this->concierge->mode() !== Mode::Workspaces) {
            throw new UnsupportedConfiguration("Leaving a workspace needs workspace mode; set 'mode' => 'workspaces' in config/concierge.php. App-wide mode has no workspaces.");
        }

        $membership = $this->concierge->membership($user, $workspace);

        if (! $membership || $membership->status === MembershipStatus::Removed) {
            Response::deny(__('You are not a member of this workspace.'), 'not_member')->authorize();
        }

        return ManagedMembership::mutate($membership, function (Workspace $workspace, Membership $membership) use ($user) {
            if ($membership->status === MembershipStatus::Removed) {
                Response::deny(__('You are not a member of this workspace.'), 'not_member')->authorize();
            }

            if ($this->concierge->isOwner($membership)) {
                Response::deny(__('Transfer ownership before you leave this workspace.'), 'owner_must_transfer')->authorize();
            }

            $this->concierge->context()->run($workspace, function () use ($user) {
                $user->syncRoles([]);
                $user->syncPermissions([]);
            }, [$user]);

            $membership->forceFill([
                'status' => MembershipStatus::Removed,
                'removed_at' => now(),
                'removed_by_id' => $user->getKey(),
            ])->save();

            MembershipRemoved::dispatch($workspace->getKey(), $membership->getKey(), $user->getKey());

            return $membership;
        });
    }
}
