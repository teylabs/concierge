<?php

namespace Tey\Concierge\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Models\Account;

/**
 * Runs an app-wide write under its lock root. There is no workspace row to
 * lock, so the root is the account rows of every owner plus the actor and the
 * target, taken ascending by user id and created when missing. Two writes
 * that could both reduce the owner count therefore serialise, which is what
 * keeps the last-owner rule true under concurrency.
 */
final class ManagedAccount
{
    /**
     * @template TReturn
     *
     * @param  Closure(Account, ?Account): TReturn  $callback  the actor's and the target's locked rows
     * @return TReturn
     */
    public static function mutate(Member $actor, ?Member $target, Closure $callback): mixed
    {
        return DB::transaction(function () use ($actor, $target, $callback) {
            $ids = [(int) $actor->getKey(), ...app(AppScope::class)->ownerIds()];

            if ($target) {
                $ids[] = (int) $target->getKey();
            }

            $locked = Locks::accounts($ids);

            return $callback($locked[(int) $actor->getKey()], $target ? $locked[(int) $target->getKey()] : null);
        });
    }
}
