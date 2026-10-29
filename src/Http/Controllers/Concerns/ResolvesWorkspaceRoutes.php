<?php

namespace Tey\Concierge\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Http\Abilities;
use Tey\Concierge\Models\Invitation;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Support\ConciergeModels;

/**
 * The workspace is the bound WorkspaceContext's current one, never a route
 * parameter, and every membership or invitation is looked up inside it, so
 * another workspace's id is a 404. Route parameters are read by name, so an
 * app prefix with its own parameters does not shift them.
 */
trait ResolvesWorkspaceRoutes
{
    protected function actor(Request $request): Member
    {
        $user = $request->user(config('concierge.guard'));

        abort_unless($user instanceof Member, 403);

        return $user;
    }

    protected function workspace(): Workspace
    {
        $workspace = app(Concierge::class)->context()->current();

        abort_if($workspace === null, 404);

        return $workspace;
    }

    /** The workspace, once the actor may see its members. */
    protected function viewableWorkspace(Request $request): Workspace
    {
        $workspace = $this->workspace();

        Gate::forUser($this->actor($request))->authorize(Abilities::VIEW_MEMBERS, [$workspace]);

        return $workspace;
    }

    protected function routeMembership(Request $request): Membership
    {
        $key = (string) $request->route('membership');

        abort_unless(ctype_digit($key), 404);

        /** @var Membership */
        return ConciergeModels::membership()::query()
            ->where('workspace_id', $this->workspace()->getKey())
            ->whereKey((int) $key)
            ->firstOrFail();
    }

    protected function routeInvitation(Request $request): Invitation
    {
        /** @var Invitation */
        return ConciergeModels::invitation()::query()
            ->where('workspace_id', $this->workspace()->getKey())
            ->where('public_id', (string) $request->route('invitation'))
            ->firstOrFail();
    }

    /**
     * JSON clients get a 204. The pages get a 303: to $to when given, else
     * back to where they were, else the members list.
     */
    protected function done(Request $request, ?string $to = null): Response
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->noContent();
        }

        return $to !== null
            ? redirect()->to($to, 303)
            : $this->redirectBack(route('concierge.members.index'));
    }

    protected function redirectBack(string $fallback): RedirectResponse
    {
        return redirect()->back(303, [], $fallback);
    }
}
