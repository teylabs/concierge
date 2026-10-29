<?php

namespace Tey\Concierge\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\CurrentWorkspace;

/**
 * For concierge's member routes: when the app has not set a workspace context
 * for this request, use the session's current workspace (CurrentWorkspace),
 * which falls back to the user's oldest active membership. An app that sets
 * the context itself, in its own middleware, keeps it. concierge.member runs
 * next and decides who may pass.
 */
class UseCurrentWorkspace
{
    public function __construct(protected Concierge $concierge) {}

    public function handle(Request $request, Closure $next): Response
    {
        $context = $this->concierge->context();
        $user = $request->user();

        if ($context->current() !== null || ! $user instanceof Member || ! $request->hasSession()) {
            return $next($request);
        }

        $current = app(CurrentWorkspace::class);
        $remembered = $current->id() === null ? null : ConciergeModels::workspace()::query()->find($current->id());

        // A remembered workspace the user has been suspended or removed from stays current, so
        // concierge.member can repoint it and send them to workspaces.no_workspace_url.
        $workspace = $remembered instanceof Workspace && $this->concierge->membership($user, $remembered) !== null
            ? $remembered
            : $current->resolve($user);

        if ($workspace === null) {
            return $next($request);
        }

        $context->enter($workspace, [$user]);

        try {
            return $next($request);
        } finally {
            $context->leave();
        }
    }
}
