<?php

namespace Tey\Concierge\Contracts;

use Tey\Concierge\Support\WorkspaceMember;

/**
 * Where a workspace's members and their roles are read from: concierge's own
 * tables, or a starter kit's team pivot. Only active members are returned.
 */
interface MembershipSource
{
    /**
     * Whether concierge writes this workspace's memberships. A source that
     * returns false is read-only, and concierge's write actions refuse it.
     */
    public function managesMembers(Workspace $workspace): bool;

    /** @return list<WorkspaceMember> ordered by when they joined */
    public function members(Workspace $workspace): array;

    public function member(Workspace $workspace, Member $user): ?WorkspaceMember;

    /** @return list<WorkspaceMember> one per workspace the user is an active member of */
    public function workspacesOf(Member $user): array;
}
