<?php

namespace Tey\Concierge\LocalAuth\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tey\Concierge\LocalAuth\LocalLogin;

/**
 * The first thing the local-login route does: refuse unless local login is
 * available right now (strictly `local`, enabled, an account configured).
 *
 * It is listed ahead of the CSRF, guest and throttle middleware, so a route cached
 * in `local` and booted elsewhere is denied with a 404 before any of them runs, and
 * none of what they need (a token, a guard, a limiter) has to exist there. Laravel
 * may still start the session first: its middleware priority list is not ours.
 */
class EnsureLocalLoginAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app(LocalLogin::class)->isAvailable(), 404);

        return $next($request);
    }
}
