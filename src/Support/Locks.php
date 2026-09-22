<?php

namespace Tey\Concierge\Support;

use Illuminate\Database\Eloquent\Model;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Models\Ownership;

/**
 * Lock order for every write: workspace, ownership, memberships ascending id, invitation.
 */
final class Locks
{
    /** @return Model&Workspace */
    public static function workspace(int $id): Workspace
    {
        return ConciergeModels::workspace()::query()->lockForUpdate()->findOrFail($id);
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
}
