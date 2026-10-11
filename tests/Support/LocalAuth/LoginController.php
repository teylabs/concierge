<?php

namespace Tey\Concierge\Tests\Support\LocalAuth;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Tey\Concierge\LocalAuth\LocalLogin;

// Fixture only: obtain a real web session and CSRF token through the HTTP kernel, and
// report what this server process decided, so HTTP-only boots (php-fpm, artisan serve)
// can be probed the same way as the in-process ones.
class LoginController
{
    public function __invoke(LocalLogin $localLogin): JsonResponse
    {
        return response()->json([
            'token' => csrf_token(),
            'application_environment' => app()->environment(),
            'config_cached' => app()->configurationIsCached(),
            'routes_cached' => app()->routesAreCached(),
            'route' => Route::has(LocalLogin::ROUTE),
            'offer' => $localLogin->offer() !== null,
            'guest' => auth()->guard()->guest(),
        ]);
    }
}
