<?php

use Illuminate\Support\Facades\DB;
use Tey\Concierge\Actions\SyncRoleDefinitions;
use Tey\Concierge\Concierge;
use Tey\Concierge\Models\Account;
use Tey\Concierge\Support\AppScope;
use Tey\Concierge\Support\Roles;
use Tey\Concierge\Tests\TestCase;
use Workbench\App\Models\User;

require_once __DIR__.'/../Support/Concurrency.php';
require_once __DIR__.'/../Support/LocalAuth/requests.php';

local_login_preserve_process_environment();

it('makes only the first local account an app-wide owner', function (bool $existingAccount) {
    local_login_enable();
    SyncRoleDefinitions::run();

    if ($existingAccount) {
        host_user('local');
    }

    local_login_click()->assertRedirect('/');
    $owner = local_login_account();

    $this->assertAuthenticatedAs($owner);
    expect(app(Concierge::class)->isOwner($owner))->toBeTrue()
        ->and(app_roles($owner))->toBe(['owner'])
        ->and(Account::query()->find($owner->id)->isActive())->toBeTrue();

    auth()->logout();
    config(['concierge.local_login.account.email' => 'second@example.test']);
    local_login_click()->assertRedirect('/');
    $second = User::query()->where('email', 'second@example.test')->sole();

    $this->assertAuthenticatedAs($second);
    expect(app_roles($second))->toBe([])
        ->and(Account::query()->find($second->id))->toBeNull()
        ->and(Account::query()->count())->toBe(1);
})->with([false, true]);

it('grants nothing when an app-wide owner exists or first owner is off', function (bool $existingOwner) {
    local_login_enable(['first_owner' => $existingOwner]);
    SyncRoleDefinitions::run();

    if ($existingOwner) {
        app_owner();
    }

    $before = DB::table('model_has_roles')->get()->all();
    local_login_click()->assertRedirect('/');

    $this->assertAuthenticatedAs(local_login_account());
    expect(app_roles(local_login_account()))->toBe([])
        ->and(Account::query()->find(local_login_account()->id))->toBeNull()
        ->and(DB::table('model_has_roles')->get()->all())->toEqual($before);
})->with([false, true]);

it('signs in two local accounts concurrently but establishes only one app-wide owner', function () {
    if (! TestCase::usesPostgres() || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Concurrency claims require PostgreSQL (CONCIERGE_TEST_DB=pgsql) and pcntl.');
    }

    local_login_enable();
    SyncRoleDefinitions::run();
    $accounts = [host_user('local'), host_user('second')];
    $click = function (User $account): string {
        config(['concierge.local_login.account.email' => $account->email]);

        return (string) local_login_click()->status();
    };

    $results = race(Roles::find(Roles::owner())->getKey(), [
        'first' => fn () => $click($accounts[0]),
        'second' => fn () => $click($accounts[1]),
    ], 'roles');

    expect($results)->toBe(['first' => '302', 'second' => '302'])
        ->and(app(AppScope::class)->owners())->toHaveCount(1)
        ->and(DB::table('model_has_roles')->count())->toBe(1)
        ->and(Account::query()->count())->toBe(1);
});
