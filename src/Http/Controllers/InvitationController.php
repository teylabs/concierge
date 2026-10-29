<?php

namespace Tey\Concierge\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Tey\Concierge\Actions\ResendInvitation;
use Tey\Concierge\Actions\RevokeInvitation;
use Tey\Concierge\Http\Abilities;
use Tey\Concierge\Http\Controllers\Concerns\ResolvesWorkspaceRoutes;

/** Resending and revoking an invitation of the current workspace. */
class InvitationController
{
    use ResolvesWorkspaceRoutes;

    public function resend(Request $request): Response
    {
        $this->viewableWorkspace($request);
        $actor = $this->actor($request);
        $invitation = $this->routeInvitation($request);

        Gate::forUser($actor)->authorize(Abilities::MANAGE_INVITATION, [$invitation]);
        $message = $request->validate(['message' => ['nullable', 'string', 'max:500']])['message'] ?? null;

        ResendInvitation::run($actor, $invitation, $message);

        return $this->done($request);
    }

    public function destroy(Request $request): Response
    {
        $this->viewableWorkspace($request);
        $actor = $this->actor($request);
        $invitation = $this->routeInvitation($request);

        Gate::forUser($actor)->authorize(Abilities::MANAGE_INVITATION, [$invitation]);

        RevokeInvitation::run($actor, $invitation);

        return $this->done($request);
    }
}
