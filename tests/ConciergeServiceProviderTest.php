<?php

use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
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

it('reads owner emails from the environment, trimming whitespace and dropping blanks', function () {
    $env = Env::getRepository();
    $before = $env->get('CONCIERGE_OWNERS');
    $env->clear('CONCIERGE_OWNERS');
    $env->set('CONCIERGE_OWNERS', ' first@example.test, ,Second@Example.Test,, ');

    try {
        $config = require __DIR__.'/../config/concierge.php';

        expect($config['owners'])->toBe(['first@example.test', 'Second@Example.Test']);
    } finally {
        $env->clear('CONCIERGE_OWNERS');
        if ($before !== null) {
            $env->set('CONCIERGE_OWNERS', $before);
        }
    }
});

it('publishes only the config file and the package migrations', function () {
    $published = collect(ServiceProvider::pathsToPublish(ConciergeServiceProvider::class))
        ->keys()
        ->map(fn (string $from) => basename($from))
        ->sort()
        ->values()
        ->all();

    expect($published)->toBe(['concierge.php', 'create_concierge_tables.php', 'create_concierge_tenant_invitations_table.php', 'create_concierge_user_accounts_table.php', 'update_concierge_invitations_with_message.php']);
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

it('keeps the membership feature on by default, with its bindings, alias, command and migration', function () {
    expect(config('concierge.features.membership'))->toBeTrue()
        ->and(ConciergeServiceProvider::membershipEnabled())->toBeTrue()
        ->and(app('router')->getMiddleware())->toHaveKey('concierge.member')
        ->and(array_keys(Artisan::all()))->toContain('concierge:sync-roles')
        ->and(array_map('realpath', app('migrator')->paths()))->toContain(realpath(__DIR__.'/../database/migrations/create_concierge_tables.php'));
});

it('ships local login off, with no account, throttled and with no hard-coded redirect', function () {
    expect(config('concierge.local_login'))->toBe([
        'enabled' => false,
        'first_owner' => true,
        'account' => ['email' => null, 'name' => null, 'attributes' => []],
        'identity' => 'email',
        'guard' => null,
        'redirect' => null,
        'throttle' => 10,
    ]);
});
