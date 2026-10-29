<?php

namespace Tey\Concierge\Http\Controllers;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Tey\Concierge\Actions\AcceptInvitation;
use Tey\Concierge\Actions\InspectInvitation;
use Tey\Concierge\Contracts\Member;
use Tey\Concierge\Contracts\PageResponder;
use Tey\Concierge\Enums\InvitationState;
use Tey\Concierge\Exceptions\InvitationRejected;
use Tey\Concierge\Support\AcceptInvitationPage;
use Tey\Concierge\Support\CurrentWorkspace;
use Tey\Concierge\Support\InvitationHandoff;

/**
 * The page an invitation email links to, in either mode. A signed-out visitor
 * is offered sign-in or registration and brought back here afterwards
 * (InvitationHandoff); the wrong account may switch. Accepting needs a
 * signed-in user, and AcceptInvitation checks everything again.
 */
class AcceptInvitationController
{
    public function __construct(protected PageResponder $pages) {}

    public function show(Request $request, AcceptInvitationPage $page): Responsable|Response
    {
        $token = $this->token($request);
        $user = $this->user($request);
        $inspection = InspectInvitation::run($user, $token);

        if ($user === null && in_array($inspection->state, [InvitationState::SignInRequired, InvitationState::RegisterRequired], true)) {
            InvitationHandoff::start($token, route('concierge.invitations.show', ['token' => $token], absolute: false));
        }

        $named = fn (string $name) => Route::has($name) ? route($name) : null;

        $props = $page->props($user, $inspection, [
            'accept' => route('concierge.invitations.accept', ['token' => $token]),
            'login' => $named('login'),
            'register' => $named('register'),
            'logout' => $named('logout'),
            'switchAccount' => route('concierge.invitations.switch-account', ['token' => $token]),
            'verify' => $named('verification.notice'),
            'home' => url((string) config('concierge.routes.home', '/')),
        ]);

        return $this->pages->render($request, (string) config('concierge.routes.pages.accept', 'Concierge/AcceptInvitation'), $props);
    }

    public function store(Request $request): Response
    {
        $token = $this->token($request);
        $user = $this->user($request);

        abort_if($user === null, 401);

        try {
            $acceptance = AcceptInvitation::run($user, $token);
        } catch (InvitationRejected $rejected) {
            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['state' => $rejected->state->value], 409)
                : redirect()->route('concierge.invitations.show', ['token' => $token], 303);
        }

        Cookie::queue(InvitationHandoff::forget());

        if ($acceptance->membership !== null && $request->hasSession()) {
            app(CurrentWorkspace::class)->remember($acceptance->membership->workspace);
        }

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->noContent()
            : redirect()->to(url((string) config('concierge.routes.home', '/')), 303);
    }

    public function switchAccount(Request $request): Response
    {
        $token = $this->token($request);

        return InvitationHandoff::switchAccount(
            $request,
            $token,
            route('concierge.invitations.show', ['token' => $token], absolute: false),
            Route::has('login') ? route('login') : url('/'),
        );
    }

    protected function token(Request $request): string
    {
        return (string) $request->route('token');
    }

    protected function user(Request $request): ?Member
    {
        $user = $request->user(config('concierge.guard'));

        return $user instanceof Member ? $user : null;
    }
}
