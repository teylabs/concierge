<?php

namespace Tey\Concierge\LocalAuth;

use Illuminate\Support\Facades\Route;
use Tey\Concierge\Data\UI\LocalLoginOffer;

/**
 * The single answer to "is one-click local login available right now?".
 *
 * Both the login page (the button) and the endpoint (the action) ask it, on every
 * request, so neither depends on a route having been registered earlier: a cached
 * route that outlives an environment switch is refused here too. Strictly the
 * `local` environment, enabled in config, and an account configured. No host-name
 * guessing: a `project.test` Herd site is local because its environment is.
 */
final class LocalLogin
{
    public const ROUTE = 'concierge.local-login.store';

    public function isAvailable(): bool
    {
        return app()->environment('local')
            && config('concierge.local_login.enabled') === true
            && $this->email() !== '';
    }

    public function email(): string
    {
        $email = config('concierge.local_login.account.email');

        return is_string($email) ? trim($email) : '';
    }

    public function guard(): string
    {
        $guard = config('concierge.local_login.guard') ?? config('auth.defaults.guard');

        return is_string($guard) ? $guard : 'web';
    }

    public function offer(): ?LocalLoginOffer
    {
        if (! $this->isAvailable() || ! Route::has(self::ROUTE)) {
            return null;
        }

        return new LocalLoginOffer(url: route(self::ROUTE, absolute: false), email: $this->email());
    }
}
