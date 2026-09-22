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

it('publishes nothing but the config file', function () {
    $published = collect(ServiceProvider::pathsToPublish(ConciergeServiceProvider::class))
        ->mapWithKeys(fn (string $to, string $from) => [realpath($from) => $to])
        ->all();

    expect($published)->toBe([realpath(__DIR__.'/../config/concierge.php') => config_path('concierge.php')]);
});

it('publishes the package defaults under the concierge-config tag', function () {
    $this->artisan('vendor:publish', ['--tag' => 'concierge-config'])->assertSuccessful();

    expect(require config_path('concierge.php'))->toBe(['invitations' => ['expires_after_days' => 7]]);
});

it('keeps host config values over package defaults', function () {
    config()->set('concierge.invitations.expires_after_days', 14);

    app()->register(ConciergeServiceProvider::class, force: true);

    expect(config('concierge.invitations.expires_after_days'))->toBe(14);
});
