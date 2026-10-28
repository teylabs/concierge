<?php

namespace Tey\Concierge\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\ScopedAccess;

/**
 * `concierge.scoped:{parameter}` lets a request through only when the signed-in
 * user holds an active scope for the route's bound record (see ScopedAccess).
 * Put it after route model binding (SubstituteBindings, which the web group
 * runs). `concierge.scoped:{parameter},members` also lets active members of
 * the current workspace who are not scoped guests through, for a route that
 * full members and guests share; their roles are still for the app to check.
 * Anything else is a 403, and a parameter that is not a bound model too.
 */
class RequireScopedAccess
{
    public function __construct(protected Concierge $concierge, protected ScopedAccess $access) {}

    public function handle(Request $request, Closure $next, string $parameter, ?string $members = null): Response
    {
        $user = $request->user();
        $record = $request->route($parameter);

        abort_unless($user instanceof Member && $record instanceof Model, 403);

        if ($members === 'members' && $this->isFullMember($user)) {
            return $next($request);
        }

        abort_unless($this->access->allows($user, $record), 403);

        return $next($request);
    }

    protected function isFullMember(Member $user): bool
    {
        $workspace = $this->concierge->context()->current();
        $membership = $workspace ? $this->concierge->membership($user, $workspace) : null;

        return $membership?->isActive() === true && ! $this->concierge->isScopedGuest($membership);
    }
}
