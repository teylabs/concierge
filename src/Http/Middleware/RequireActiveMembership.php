<?php

namespace Tey\Concierge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\MembershipSource;
use Tey\Concierge\Enums\Mode;
use Tey\Concierge\Enums\WorkspaceEngine;
use Tey\Concierge\Support\CurrentWorkspace;

/**
 * Fails closed. Workspace mode: no workspace context, or a workspace the user
 * was never a member of, is a 404. A membership that is no longer active (read
 * fresh on every request) repoints the current workspace and redirects to the
 * no-workspace route; a JSON request gets a 403. The kit engine cannot tell a
 * past member from a stranger, so anything but a member is a 403 there. App-wide
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

        abort_unless($user instanceof Member, 403);

        if (WorkspaceEngine::configured() === WorkspaceEngine::Kit) {
            abort_unless(app(MembershipSource::class)->member($workspace, $user) !== null, 403);

            return $next($request);
        }

        $membership = $this->concierge->membership($user, $workspace);

        // A workspace that never had the user stays hidden.
        abort_if($membership === null, 404);

        if (! $membership->isActive()) {
            abort_if($request->expectsJson(), 403);

            if ($request->hasSession()) {
                app(CurrentWorkspace::class)->resolve($user);
            }

            return redirect((string) config('concierge.workspaces.no_workspace_url', '/'));
        }

        return $next($request);
    }
}
