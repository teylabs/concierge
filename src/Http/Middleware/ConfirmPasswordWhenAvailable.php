<?php

namespace Tey\Concierge\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's password.confirm, when the app has a password.confirm route
 * (Fortify and the starter kits do). It is looked up per request because the
 * app's routes load after concierge's. Without the route the request goes on,
 * and TransferOwnership still refuses until the password was confirmed.
 */
class ConfirmPasswordWhenAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Route::has('password.confirm')) {
            return $next($request);
        }

        return app(RequirePassword::class)->handle($request, $next);
    }
}
