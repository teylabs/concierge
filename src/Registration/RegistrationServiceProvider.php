<?php

namespace Tey\Concierge\Registration;

use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Contracts\RegisterViewResponse;
use Tey\Concierge\ConciergeServiceProvider;
use Tey\Concierge\Registration\Fortify\GatedRegisterView;
use Tey\Concierge\Registration\Fortify\GatedUserCreator;

/**
 * Registration gating. Registered by the Concierge provider whether or not the
 * membership feature is on. When Fortify is installed the gate is wired in
 * here, with no app code: the app's CreatesNewUsers and register view are
 * wrapped as they resolve, whichever provider boots first.
 */
class RegistrationServiceProvider extends ServiceProvider
{
    /**
     * Wired at boot, once setup is known; extending a binding that has already
     * resolved still wraps it, so the order against Fortify doesn't matter.
     */
    public function boot(): void
    {
        if (! ConciergeServiceProvider::setUp() || ! self::fortifyInstalled()) {
            return;
        }

        $this->app->extend(CreatesNewUsers::class, fn (CreatesNewUsers $creator) => new GatedUserCreator($creator));
        $this->app->extend(RegisterViewResponse::class, fn (RegisterViewResponse $view) => new GatedRegisterView($view));
    }

    public static function fortifyInstalled(): bool
    {
        return interface_exists(CreatesNewUsers::class);
    }
}
