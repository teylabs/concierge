<?php

namespace Tey\Concierge\Tests\Setup\Fixtures;

use Illuminate\Support\ServiceProvider;
use Tey\Concierge\Concierge;
use Tey\Concierge\LocalAuth\LocalLogin;

/** A host that binds concierge services itself while it registers, after concierge's provider. */
class HostBindingProvider extends ServiceProvider
{
    public static ?Concierge $concierge = null;

    public static ?LocalLogin $localLogin = null;

    public function register(): void
    {
        $this->app->singleton(Concierge::class, fn ($app) => static::$concierge ??= $app->build(Concierge::class));
        $this->app->singleton(LocalLogin::class, fn ($app) => static::$localLogin ??= $app->build(LocalLogin::class));
    }
}
