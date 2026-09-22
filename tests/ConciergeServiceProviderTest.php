<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Tey\Concierge\ConciergeServiceProvider;

afterEach(function () {
    File::delete(config_path('concierge.php'));
});

it('declares the provider for laravel package discovery', function () {
    $composer = json_decode(File::get(__DIR__.'/../composer.json'), true);

    expect($composer['extra']['laravel']['providers'])->toBe([ConciergeServiceProvider::class]);
});

it('defaults invitation expiry to seven days', function () {
    expect(config('concierge.invitations.expires_after_days'))->toBe(7);
});

it('publishes only the config file and the package migration', function () {
    $published = collect(ServiceProvider::pathsToPublish(ConciergeServiceProvider::class))
        ->keys()
        ->map(fn (string $from) => basename($from))
        ->sort()
        ->values()
        ->all();

    expect($published)->toBe(['concierge.php', 'create_concierge_tables.php']);
});

it('publishes the package defaults under the concierge-config tag', function () {
    $this->artisan('vendor:publish', ['--tag' => 'concierge-config'])->assertSuccessful();

    expect(require config_path('concierge.php'))->toBe(require __DIR__.'/../config/concierge.php');
});

it('keeps host config values over package defaults', function () {
    config()->set('concierge.invitations.expires_after_days', 14);

    app()->register(ConciergeServiceProvider::class, force: true);

    expect(config('concierge.invitations.expires_after_days'))->toBe(14);
});
