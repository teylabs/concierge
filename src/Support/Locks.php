<?php

namespace Tey\Concierge\Support;

use Illuminate\Database\Eloquent\Model;
use Tey\Concierge\Contracts\MembershipSource;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Enums\AccountStatus;
use Tey\Concierge\Enums\Mode;
use Tey\Concierge\Exceptions\WorkspaceNotManaged;
use Tey\Concierge\Models\Account;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Models\Ownership;

/**
 * Lock order for every write: workspace, ownership, memberships ascending id, invitation.
 * App-wide mode has no workspace row; its root is account rows ascending user id.
 */
final class Locks
{
    /**
     * Every write starts here, so this is also where a workspace whose members
     * concierge only reads (a starter kit team) is refused.
     *
     * @return Model&Workspace
     */
    public static function workspace(int $id): Workspace
    {
        ConciergeModels::assertWorkspaces('use the app-wide actions (InviteUser, ChangeUserRoles, RemoveUser)');

        $workspace = ConciergeModels::workspace()::query()->lockForUpdate()->findOrFail($id);

        self::assertManaged($workspace);

        return $workspace;
    }

    /**
     * Workspace mode only: app mode is refused on its own, and has no kit teams.
     */
    public static function assertManaged(Workspace $workspace): void
    {
        if (Mode::configured() === Mode::Workspaces && ! app(MembershipSource::class)->managesMembers($workspace)) {
            throw WorkspaceNotManaged::kitTeam($workspace);
        }
    }

    public static function ownership(int $workspaceId): ?Ownership
    {
        return ConciergeModels::ownership()::query()->lockForUpdate()->find($workspaceId);
    }

    /**
     * @param  array<int, int|null>  $ids
     * @return array<int, Membership> keyed by id
     */
    public static function memberships(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids);

        return ConciergeModels::membership()::query()
            ->whereKey($ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * App-wide lock root. Rows are created on first write, so a user who has
     * never been written about still has something to lock.
     *
     * @param  array<int, int|null>  $userIds
     * @return array<int, Account> keyed by user id
     */
    public static function accounts(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds)));
        sort($userIds);

        if ($userIds === []) {
            return [];
        }

        $now = now();

        ConciergeModels::account()::query()->insertOrIgnore(array_map(fn (int $id) => [
            'user_id' => $id,
            'status' => AccountStatus::Active->value,
            'created_at' => $now,
            'updated_at' => $now,
        ], $userIds));

        return ConciergeModels::account()::query()
            ->whereKey($userIds)
            ->orderBy('user_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('user_id')
            ->all();
    }
}
