<?php

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;
use Tey\Concierge\ConciergeServiceProvider;

it('treats an app with its own config file as set up', function () {
    expect(ConciergeServiceProvider::setUp())->toBeTrue();
});

it('migrates and registers as before', function () {
    $this->artisan('migrate')->assertSuccessful();

    expect(Schema::hasTable('concierge_memberships'))->toBeTrue()
        ->and(app(Router::class)->getMiddleware())->toHaveKey('concierge.member');
});

it('says setup is done in about', function () {
    $this->artisan('about', ['--only' => 'concierge'])
        ->expectsOutputToContain('Done')
        ->assertSuccessful();
});
