<?php

namespace Tey\Concierge\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Models\Membership;

/**
 * Runs a membership mutation under workspace -> ownership -> membership locks.
 */
final class ManagedMembership
{
    /**
     * @template TReturn
     *
     * @param  Closure(Workspace, Membership): TReturn  $callback
     * @return TReturn
     */
    public static function mutate(Membership $membership, Closure $callback): mixed
    {
        return DB::transaction(function () use ($membership, $callback) {
            $workspace = Locks::workspace($membership->workspace_id);
            Locks::ownership($workspace->getKey());
            $locked = Locks::memberships([$membership->getKey()])[$membership->getKey()];

            return $callback($workspace, $locked);
        });
    }
}
