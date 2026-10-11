<?php

namespace Tey\Concierge\Http\Middleware;

use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Tey\Concierge\Concierge;
use Tey\Concierge\Contracts\Member;

/**
 * Stops a deactivated user on their next request, in both modes. A session is
 * signed out and invalidated; a token request (Sanctum, Passport) is refused
 * with a 403 and the token is left alone, so restoring the user brings it
 * back. Put it after the authentication middleware, for example
 * ['auth:sanctum', 'concierge.active'].
 */
class EnsureAccountActive
{
    public function __construct(protected Concierge $concierge) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof Member || $this->concierge->account($user)->isActive()) {
            return $next($request);
        }

        $this->signOut($request, $user);

        $message = __('This account is deactivated.');

        if ($request->expectsJson() || $request->bearerToken() !== null || ! Route::has('login')) {
            abort(403, $message);
        }

        return redirect()->route('login')->withErrors(['email' => $message]);
    }

    protected function signOut(Request $request, Member $user): void
    {
        foreach (array_keys((array) config('auth.guards')) as $name) {
            $guard = Auth::guard($name);

            if ($guard instanceof SessionGuard && (string) $guard->id() === (string) $user->getKey()) {
                $guard->logout();
            }
        }

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
