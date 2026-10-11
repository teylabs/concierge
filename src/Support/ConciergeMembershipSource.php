<?php

namespace Tey\Concierge\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\MembershipSource;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Enums\MembershipStatus;
use Tey\Concierge\Models\Membership;

/**
 * Concierge's own engine: concierge_memberships and concierge_ownerships, with
 * roles from Spatie assignments scoped to the workspace.
 */
class ConciergeMembershipSource implements MembershipSource
{
    public function managesMembers(Workspace $workspace): bool
    {
        return true;
    }

    public function members(Workspace $workspace): array
    {
        return $this->describe($this->active()->where('workspace_id', $workspace->getKey())->get());
    }

    public function member(Workspace $workspace, Member $user): ?WorkspaceMember
    {
        return $this->describe($this->active()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $user->getKey())
            ->get())[0] ?? null;
    }

    public function workspacesOf(Member $user): array
    {
        return $this->describe($this->active()->where('user_id', $user->getKey())->get());
    }

    /** @return Builder<Membership> */
    protected function active()
    {
        return ConciergeModels::membership()::query()
            ->with('workspace')
            ->where('status', MembershipStatus::Active)
            ->orderBy('joined_at')
            ->orderBy('id');
    }

    /**
     * @param  Collection<int, Membership>  $memberships
     * @return list<WorkspaceMember>
     */
    protected function describe(Collection $memberships): array
    {
        $owners = ConciergeModels::ownership()::query()
            ->whereIn('workspace_id', $memberships->pluck('workspace_id')->unique()->all())
            ->pluck('membership_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $roles = [];

        foreach ($memberships->groupBy('workspace_id') as $workspaceId => $group) {
            $roles[(int) $workspaceId] = MembershipDirectory::rolesFor((int) $workspaceId, $group->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        }

        return $memberships->map(fn (Membership $membership) => new WorkspaceMember(
            workspace: $membership->workspace,
            userId: (int) $membership->user_id,
            roles: $roles[(int) $membership->workspace_id][(int) $membership->user_id] ?? [],
            owner: in_array((int) $membership->getKey(), $owners, true),
            joinedAt: $membership->joined_at,
        ))->values()->all();
    }
}
