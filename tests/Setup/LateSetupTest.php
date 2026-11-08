<?php

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Validated;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tey\Concierge\Concierge;
use Tey\Concierge\ConciergeServiceProvider;
use Tey\Concierge\Contracts\WorkspaceContext;

it('is set up by a package that sets the switch after concierge registers', function () {
    expect(file_exists(config_path('concierge.php')))->toBeFalse()
        ->and(ConciergeServiceProvider::setUp())->toBeTrue();
});

it('registers routes, middleware, listeners and bindings', function () {
    expect(Route::has('concierge.members.index'))->toBeTrue()
        ->and(app(Router::class)->getMiddleware())->toHaveKeys(['concierge.member', 'concierge.active', 'concierge.scoped'])
        ->and(Event::hasListeners(Login::class))->toBeTrue()
        ->and(Event::hasListeners(Validated::class))->toBeTrue()
        ->and(Event::hasListeners(PasswordReset::class))->toBeTrue()
        ->and(app()->bound(Concierge::class))->toBeTrue()
        ->and(app()->bound(WorkspaceContext::class))->toBeTrue();
});

it('creates the concierge tables on migrate', function () {
    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::hasTable('concierge_memberships'))->toBeTrue();
});

it('says setup is done in about', function () {
    $this->artisan('about', ['--only' => 'concierge'])
        ->expectsOutputToContain('Done')
        ->assertSuccessful();
});
