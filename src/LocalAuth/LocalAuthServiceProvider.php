<?php

namespace Tey\Concierge\LocalAuth;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Tey\Concierge\LocalAuth\Controllers\LocalLoginController;
use Tey\Concierge\LocalAuth\Middleware\EnsureLocalLoginAvailable;

/**
 * One-click local login. Registered by the Concierge provider; it does nothing
 * unless concierge.local_login.enabled is true AND the environment is `local`.
 * It needs no workspace, membership or permission setup.
 */
class LocalAuthServiceProvider extends ServiceProvider
{
    public const LIMITER = 'concierge-local-login';

    public function register(): void
    {
        $this->app->singleton(LocalLogin::class);
    }

    public function boot(): void
    {
        $this->defineRateLimiter();

        if ($this->app->environment('local')) {
            $this->registerRoutes();
        }
    }

    /**
     * Defined in EVERY environment, on purpose: a route cached in `local` and booted
     * elsewhere still names this limiter, and a missing one must never be what a
     * request trips over. (The route itself is denied first by the middleware.)
     */
    private function defineRateLimiter(): void
    {
        RateLimiter::for(self::LIMITER, fn (Request $request) => Limit::perMinute((int) config('concierge.local_login.throttle', 10))->by($request->ip()));
    }

    /**
     * Public so a test can switch the environment and register the route the way
     * a real local boot would. The `guest` middleware is qualified with the
     * configured guard: a bare `guest` only checks the default guard.
     */
    public function registerRoutes(): void
    {
        $this->defineRateLimiter();

        if ($this->app->routesAreCached()) {
            return;
        }

        $guard = $this->app->make(LocalLogin::class)->guard();

        Route::middleware([EnsureLocalLoginAvailable::class, 'web', "guest:{$guard}", 'throttle:'.self::LIMITER])
            ->controller(LocalLoginController::class)
            ->prefix('local-login')
            ->as('concierge.local-login.')
            ->group(function () {
                Route::post('/', 'store')->name('store');
            });
    }
}
