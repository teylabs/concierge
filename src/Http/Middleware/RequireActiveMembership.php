<?php

namespace Tey\Concierge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\Member;

/**
 * Fails closed: no workspace context is a 404, and anything but an active
 * membership (read fresh on every request) is a 403.
 */
class RequireActiveMembership
{
    public function __construct(protected Concierge $concierge) {}

    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $this->concierge->context()->current();

        abort_if($workspace === null, 404);

        $user = $request->user();

        abort_unless($user instanceof Member && $this->concierge->membership($user, $workspace)?->isActive(), 403);

        return $next($request);
    }
}
