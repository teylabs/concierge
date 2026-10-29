<?php

namespace Tey\Concierge\Http\Controllers;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Tey\Concierge\Actions\InviteMember;
use Tey\Concierge\Contracts\PageResponder;
use Tey\Concierge\Http\Abilities;
use Tey\Concierge\Http\Controllers\Concerns\ResolvesWorkspaceRoutes;
use Tey\Concierge\Support\GrantSet;
use Tey\Concierge\Support\InviteMemberPage;
use Tey\Concierge\Support\Roles;

/** The invite form, and sending the invitation through InviteMember. */
class InviteMemberController
{
    use ResolvesWorkspaceRoutes;

    public function __construct(protected PageResponder $pages) {}

    public function create(Request $request, InviteMemberPage $page): Responsable|Response
    {
        $workspace = $this->viewableWorkspace($request);
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize(Abilities::INVITE_MEMBERS, [$workspace]);

        $props = $page->props($actor, $workspace, route('concierge.members.store'), route('concierge.members.index'));

        return $this->pages->render($request, (string) config('concierge.routes.pages.invite', 'Concierge/InviteMember'), $props);
    }

    public function store(Request $request): Response
    {
        $workspace = $this->viewableWorkspace($request);
        $actor = $this->actor($request);

        Gate::forUser($actor)->authorize(Abilities::INVITE_MEMBERS, [$workspace]);

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'distinct', Rule::in(Roles::configured())],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        InviteMember::run($actor, $workspace, $data['email'], new GrantSet($data['roles']), $data['message'] ?? null);

        return $this->done($request, route('concierge.members.index', ['tab' => 'invitations']));
    }
}
