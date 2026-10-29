<?php

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tey\Concierge\Concierge;
use Tey\Concierge\ConciergeServiceProvider;

it('treats an app with its own config file as set up', function () {
    expect(ConciergeServiceProvider::setUp())->toBeTrue();
});

it('migrates and registers as before', function () {
    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::hasTable('concierge_memberships'))->toBeTrue()
        ->and(app(Router::class)->getMiddleware())->toHaveKey('concierge.member');
});

it('keeps concierge routes off when its config has no routes key', function () {
    expect(config('concierge.routes.enabled'))->toBeFalse()
        ->and(collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'concierge.'))->all())->toBe([])
        ->and(app(Concierge::class)->deliversInvitations())->toBeFalse();
});

it('says setup is done in about', function () {
    $this->artisan('about', ['--only' => 'concierge'])
        ->expectsOutputToContain('Done')
        ->assertSuccessful();
});
