<?php

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tey\Concierge\Concierge;
use Tey\Concierge\ConciergeServiceProvider;

it('is not set up without the config file or the switch', function () {
    expect(ConciergeServiceProvider::setUp())->toBeFalse();
});

it('migrates without creating concierge tables', function () {
    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::hasTable('concierge_memberships'))->toBeFalse()
        ->and(Schema::hasTable('concierge_invitations'))->toBeFalse()
        ->and(Schema::hasTable('concierge_tenant_invitations'))->toBeFalse();
});

it('registers no routes, middleware, listeners or bindings', function () {
    $router = app(Router::class);

    expect(collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'concierge.'))->all())->toBe([])
        ->and($router->getMiddleware())->not->toHaveKeys(['concierge.member', 'concierge.active', 'concierge.scoped'])
        ->and(Event::hasListeners(Login::class))->toBeFalse()
        ->and(Event::hasListeners(PasswordReset::class))->toBeFalse()
        ->and(app()->bound(Concierge::class))->toBeFalse();
});

it('registers only the install command', function () {
    expect(collect(Artisan::all())->keys()->filter(fn ($name) => str_starts_with($name, 'concierge:'))->values()->all())->toBe(['concierge:install']);
});

it('says how to set up in about', function () {
    $this->artisan('about', ['--only' => 'concierge'])
        ->expectsOutputToContain('Not set up: run php artisan concierge:install')
        ->assertSuccessful();
});

it('keeps the config publishable', function () {
    expect(ConciergeServiceProvider::pathsToPublish(ConciergeServiceProvider::class, 'concierge-config'))->not->toBeEmpty();
});
