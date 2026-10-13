<?php

use Illuminate\Testing\TestResponse;
use Tey\Concierge\LocalAuth\LocalAuthServiceProvider;
use Tey\Concierge\LocalAuth\LocalLogin;
use Workbench\App\Models\User;

require_once __DIR__.'/ProcessEnvironment.php';

const LOCAL_LOGIN_EMAIL = 'local@example.test';

/**
 * @param  array<string, mixed>  $config  merged over the enabled configuration
 */
function local_login_enable(array $config = []): void
{
    local_login_process_environment('local');
    app()->detectEnvironment(fn () => 'local');

    config(['concierge.local_login' => array_replace_recursive(config('concierge.local_login'), [
        'enabled' => true,
        'account' => ['email' => LOCAL_LOGIN_EMAIL, 'name' => 'Local developer'],
    ], $config)]);

    app()->getProvider(LocalAuthServiceProvider::class)->registerRoutes();
    app('router')->getRoutes()->refreshNameLookups();
    app('router')->getRoutes()->refreshActionLookups();
}

function local_login_click(array $input = []): TestResponse
{
    return test()->withSession(['_token' => 'csrf'])->post(route(LocalLogin::ROUTE), ['_token' => 'csrf', ...$input]);
}

function local_login_account(): ?User
{
    return User::query()->where('email', LOCAL_LOGIN_EMAIL)->first();
}
