<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tey\Concierge\Concierge;
use Tey\Concierge\LocalAuth\LocalAuthServiceProvider;
use Tey\Concierge\LocalAuth\LocalLogin;
use Workbench\App\Models\LocalAuth\PlainAccount;

require_once __DIR__.'/../Support/LocalAuth/ProcessEnvironment.php';

local_login_preserve_process_environment();

/**
 * A host that wants only local login. The membership feature is off, there is a
 * bare authenticatable (no Member contract, no HasRoles), no workspace model and
 * no Spatie permission tables, and `migrate` must still succeed.
 */
function standalone_enable(): void
{
    local_login_process_environment('local');
    app()->detectEnvironment(fn () => 'local');

    config(['concierge.local_login' => array_replace_recursive(config('concierge.local_login'), [
        'enabled' => true,
        'account' => ['email' => 'solo@example.test', 'name' => 'Solo'],
    ])]);

    app()->getProvider(LocalAuthServiceProvider::class)->registerRoutes();
    app('router')->getRoutes()->refreshNameLookups();
}

it('migrates without the core membership migration or any user, workspace or permission setup', function () {
    expect(config('concierge.models.user'))->toBeNull()
        ->and(config('concierge.models.workspace'))->toBeNull()
        ->and(config('permission'))->toBeNull();

    $this->artisan('migrate')->assertSuccessful();

    foreach (['concierge_memberships', 'concierge_ownerships', 'concierge_invitations', 'workspaces', 'users'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse("table [{$table}] must not exist");
    }

    expect(Schema::hasTable('plain_accounts'))->toBeTrue();
});

it('registers no membership bindings, middleware alias or command', function () {
    expect(app()->bound(Concierge::class))->toBeFalse()
        ->and(app('router')->getMiddleware())->not->toHaveKey('concierge.member')
        ->and(array_keys(Artisan::all()))->not->toContain('concierge:sync-roles');
});

it('signs in the first time, creates a plain account and signs in again on a repeat click', function () {
    standalone_enable();

    $click = fn () => $this->withSession(['_token' => 'csrf'])->post(route(LocalLogin::ROUTE), ['_token' => 'csrf']);

    $click()->assertRedirect('/');
    $account = PlainAccount::query()->sole();

    $this->assertAuthenticatedAs($account);
    expect($account->email)->toBe('solo@example.test')
        ->and($account->name)->toBe('Solo')
        ->and($account->email_verified_at)->not->toBeNull();

    auth()->logout();
    $hash = $account->password;

    $click()->assertRedirect('/');

    $this->assertAuthenticatedAs($account);
    expect(PlainAccount::query()->count())->toBe(1)
        ->and(PlainAccount::query()->sole()->password)->toBe($hash);
});

it('stays absent outside local even when the host enables it', function () {
    config(['concierge.local_login.enabled' => true, 'concierge.local_login.account.email' => 'solo@example.test']);

    expect(app('router')->has(LocalLogin::ROUTE))->toBeFalse();
    $this->post('/local-login')->assertNotFound();
});
