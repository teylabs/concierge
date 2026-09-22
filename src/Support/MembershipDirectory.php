<?php

namespace Tey\Concierge\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Support\Config;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Models\Membership;

/**
 * Read snapshot of one workspace's ownership, the actor's membership and the
 * roles of a page of members, loaded in a fixed number of queries.
 *
 * Build one per page render and keep it in a local variable: it is never
 * bound in the container or held statically. Users outside the snapshot are
 * read live, and memberships of any other workspace are rejected.
 */
final class MembershipDirectory
{
    /**
     * @param  array<int, Membership|null>  $memberships  keyed by user id; null = known non-member
     * @param  array<int, list<string>>  $roles  keyed by user id
     */
    private function __construct(
        private readonly Concierge $concierge,
        private readonly Workspace $workspace,
        private readonly array $memberships,
        private readonly array $roles,
        private readonly ?Membership $owner,
    ) {}

    /** @param  iterable<Membership>  $memberships */
    public static function load(Concierge $concierge, Workspace $workspace, Member $actor, iterable $memberships): self
    {
        $workspaceId = (int) $workspace->getKey();
        $byUser = [];

        foreach ($memberships as $membership) {
            if ((int) $membership->workspace_id !== $workspaceId) {
                throw new InvalidArgumentException("Membership [{$membership->getKey()}] belongs to another workspace.");
            }

            $byUser[(int) $membership->user_id] = $membership;
        }

        $actorId = (int) $actor->getKey();
        $byUser[$actorId] ??= $concierge->membership($actor, $workspace);

        $ownerId = ConciergeModels::ownership()::query()->whereKey($workspaceId)->value('membership_id');
        $owner = null;

        if ($ownerId !== null) {
            $owner = collect($byUser)->first(fn (?Membership $m) => $m && (int) $m->getKey() === (int) $ownerId)
                ?? ConciergeModels::membership()::query()->find($ownerId);
        }

        return new self($concierge, $workspace, $byUser, self::rolesFor($workspaceId, array_keys($byUser)), $owner);
    }

    public function covers(Workspace $workspace): bool
    {
        return (int) $workspace->getKey() === (int) $this->workspace->getKey();
    }

    public function membershipOf(Member $user): ?Membership
    {
        $id = (int) $user->getKey();

        return array_key_exists($id, $this->memberships)
            ? $this->memberships[$id]
            : $this->concierge->membership($user, $this->workspace);
    }

    /** @return list<string> */
    public function roleNames(Membership $membership): array
    {
        $this->assertCovered($membership);

        return $this->roles[(int) $membership->user_id] ?? (
            array_key_exists((int) $membership->user_id, $this->memberships) ? [] : $this->concierge->roleNames($membership)
        );
    }

    public function isOwner(Membership $membership): bool
    {
        $this->assertCovered($membership);

        return $this->owner !== null && (int) $this->owner->getKey() === (int) $membership->getKey();
    }

    public function owner(): ?Membership
    {
        return $this->owner;
    }

    private function assertCovered(Membership $membership): void
    {
        if ((int) $membership->workspace_id !== (int) $this->workspace->getKey()) {
            throw new InvalidArgumentException("Membership [{$membership->getKey()}] is outside this directory's workspace.");
        }
    }

    /**
     * Mirrors Spatie HasRoles::roles() with teams: pivot scoped to the
     * workspace, role rows global (team NULL) or owned by the workspace.
     *
     * @param  list<int>  $userIds
     * @return array<int, list<string>>
     */
    private static function rolesFor(int $workspaceId, array $userIds): array
    {
        $pivot = Config::modelHasRolesTable();
        $roles = Config::rolesTable();
        $team = Config::teamForeignKey();
        $morphKey = Config::morphKey();

        $rows = DB::table($pivot)
            ->join($roles, "{$roles}.id", '=', "{$pivot}.".app(PermissionRegistrar::class)->pivotRole)
            ->where("{$pivot}.{$team}", $workspaceId)
            ->where("{$pivot}.model_type", (new (ConciergeModels::user()))->getMorphClass())
            ->whereIn("{$pivot}.{$morphKey}", $userIds)
            ->where(fn ($query) => $query->whereNull("{$roles}.{$team}")->orWhere("{$roles}.{$team}", $workspaceId))
            ->get(["{$pivot}.{$morphKey} as user_id", "{$roles}.name"]);

        $byUser = [];

        foreach ($rows as $row) {
            $byUser[(int) $row->user_id][] = (string) $row->name;
        }

        return array_map(Roles::sort(...), $byUser);
    }
}
