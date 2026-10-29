<?php

namespace Tey\Concierge\Http\Controllers;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Tey\Concierge\Actions\ChangeMemberRoles;
use Tey\Concierge\Actions\ReinstateMembership;
use Tey\Concierge\Actions\RemoveMembership;
use Tey\Concierge\Actions\SuspendMembership;
use Tey\Concierge\Actions\TransferOwnership;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\PageResponder;
use Tey\Concierge\Http\Abilities;
use Tey\Concierge\Http\ConciergeRoutes;
use Tey\Concierge\Http\Controllers\Concerns\ResolvesWorkspaceRoutes;
use Tey\Concierge\Models\Membership;
use Tey\Concierge\Support\GrantSet;
use Tey\Concierge\Support\MemberPage;
use Tey\Concierge\Support\Roles;

/**
 * One member of the current workspace: their page, and each change to their
 * membership. The Gate ability is checked first; the action then checks the
 * grant rules again under its lock.
 */
class MemberController
{
    use ResolvesWorkspaceRoutes;

    public function __construct(protected PageResponder $pages) {}

    public function show(Request $request, MemberPage $page): Responsable|Response
    {
        $this->viewableWorkspace($request);
        $membership = $this->routeMembership($request);
        $props = $page->urlsUsing(ConciergeRoutes::memberUrls())->props($this->actor($request), $membership);

        return $this->pages->render($request, (string) config('concierge.routes.pages.member', 'Concierge/Member'), $props);
    }

    public function updateRoles(Request $request): Response
    {
        [$actor, $membership] = $this->authorizeMember($request, Abilities::MANAGE_MEMBER);

        $data = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'distinct', Rule::in(Roles::configured())],
        ]);

        ChangeMemberRoles::run($actor, $membership, new GrantSet($data['roles']));

        return $this->done($request);
    }

    public function suspend(Request $request): Response
    {
        [$actor, $membership] = $this->authorizeMember($request, Abilities::MANAGE_MEMBER);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;

        SuspendMembership::run($actor, $membership, $reason);

        return $this->done($request);
    }

    public function reinstate(Request $request): Response
    {
        [$actor, $membership] = $this->authorizeMember($request, Abilities::MANAGE_MEMBER);

        ReinstateMembership::run($actor, $membership);

        return $this->done($request);
    }

    public function destroy(Request $request): Response
    {
        [$actor, $membership] = $this->authorizeMember($request, Abilities::MANAGE_MEMBER);

        RemoveMembership::run($actor, $membership);

        return $this->done($request, route('concierge.members.index'));
    }

    /** Behind password confirmation when the app has it; the action also needs a recent confirmation. */
    public function transferOwnership(Request $request): Response
    {
        [$actor, $membership] = $this->authorizeMember($request, Abilities::TRANSFER_OWNERSHIP);

        TransferOwnership::run($actor, $membership);

        return $this->done($request);
    }

    /** @return array{0: Member, 1: Membership} */
    protected function authorizeMember(Request $request, string $ability): array
    {
        $this->viewableWorkspace($request);
        $actor = $this->actor($request);
        $membership = $this->routeMembership($request);

        Gate::forUser($actor)->authorize($ability, [$membership]);

        return [$actor, $membership];
    }
}
