<?php

namespace Tey\Concierge\Tests\Support\LocalAuth;

use Illuminate\Http\JsonResponse;

// Fixture only: obtain a real web session and CSRF token through the HTTP kernel.
class LoginController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['token' => csrf_token()]);
    }
}
