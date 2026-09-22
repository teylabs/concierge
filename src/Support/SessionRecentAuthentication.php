<?php

namespace Tey\Concierge\Support;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Session\Session;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\RecentAuthentication;

/**
 * Reads Laravel's password confirmation timestamp (set by Fortify's
 * password.confirm), and only for the currently authenticated user.
 */
class SessionRecentAuthentication implements RecentAuthentication
{
    public function __construct(protected AuthFactory $auth) {}

    public function confirmed(Member $user): bool
    {
        if (! app()->bound('session.store') || (int) $this->auth->guard()->id() !== (int) $user->getKey()) {
            return false;
        }

        $confirmedAt = (int) app(Session::class)->get('auth.password_confirmed_at', 0);
        $timeout = (int) (config('concierge.recent_authentication_seconds') ?? config('auth.password_timeout', 10800));

        return $confirmedAt > 0 && time() - $confirmedAt < $timeout;
    }
}
