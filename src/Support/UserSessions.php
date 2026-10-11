<?php

namespace Tey\Concierge\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ends what a signed-in user still holds: the remember-me token and, with the
 * database session driver, their session rows. Shared by forced password reset
 * and deactivation.
 */
final class UserSessions
{
    /** Replaces the remember-me token on the model; the caller saves it. */
    public static function cycleRememberToken(Authenticatable $user): void
    {
        if ($user->getRememberTokenName()) {
            $user->setRememberToken(Str::random(60));
        }
    }

    /**
     * Delete the user's rows from the sessions table when sessions live in the
     * database; null otherwise, since other drivers cannot list a user's sessions.
     */
    public static function endDatabaseSessions(Authenticatable $user): ?int
    {
        if (config('session.driver') !== 'database') {
            return null;
        }

        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getAuthIdentifier())
            ->delete();
    }
}
