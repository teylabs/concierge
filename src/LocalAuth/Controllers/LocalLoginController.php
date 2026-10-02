<?php

namespace Tey\Concierge\LocalAuth\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tey\Concierge\LocalAuth\Actions\ResolveLocalAccount;
use Tey\Concierge\LocalAuth\Exceptions\LocalLoginRefused;
use Tey\Concierge\LocalAuth\LocalLogin;

/**
 * Sign in as the configured local account with one click: no typing, no password.
 *
 * THIS BYPASSES THE PASSWORD AND TWO-FACTOR, so it is only ever reachable in the
 * `local` environment. The route is only registered there, and this action
 * re-checks on every request (a route cache built locally and served elsewhere
 * must still fail). Anything the browser sends is ignored. It authenticates an
 * identity and nothing more: no roles, membership or recent-authentication state.
 */
class LocalLoginController
{
    public function store(Request $request, LocalLogin $localLogin, ResolveLocalAccount $resolve): RedirectResponse
    {
        abort_unless($localLogin->isAvailable(), 404);

        $guard = $localLogin->guard();

        try {
            $account = $resolve(
                guard: $guard,
                identity: (string) config('concierge.local_login.identity', 'email'),
                value: $localLogin->email(),
                name: config('concierge.local_login.account.name'),
                attributes: (array) config('concierge.local_login.account.attributes'),
            );
        } catch (LocalLoginRefused $refused) {
            return back()->withErrors(['local_login' => $refused->getMessage()]);
        }

        // No remember token: the session ends with the browser, like any other login.
        Auth::guard($guard)->login($account);

        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->intended(config('concierge.local_login.redirect') ?? '/');
    }
}
