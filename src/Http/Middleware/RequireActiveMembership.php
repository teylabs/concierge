<?php

namespace Tey\Concierge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Enums\Mode;

/**
 * Fails closed. Workspace mode: no workspace context is a 404, and anything
 * but an active membership (read fresh on every request) is a 403. App-wide
 * mode: there is no workspace to miss, so only the account must be active.
 */
class RequireActiveMembership
{
    public function __construct(protected Concierge $concierge) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($this->concierge->mode() === Mode::App) {
            abort_unless($user instanceof Member && $this->concierge->account($user)->isActive(), 403);

            return $next($request);
        }

        $workspace = $this->concierge->context()->current();

        abort_if($workspace === null, 404);

        abort_unless($user instanceof Member && $this->concierge->membership($user, $workspace)?->isActive(), 403);

        return $next($request);
    }
}
