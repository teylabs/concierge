<?php

use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tey\Concierge\Actions\EstablishWorkspace;
use Tey\Concierge\Actions\SyncRoleDefinitions;
use Tey\Concierge\Concierge;
use Tey\Concierge\ConciergeServiceProvider;
use Tey\Concierge\Contracts\WorkspaceContext;
use Tey\Concierge\Enums\Mode;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\RankRules;
use Tey\Concierge\Support\Roles;
use Workbench\App\Models\Workspace;

/** @return list<string> */
function queries_against(string $table, Closure $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $callback();

        return array_values(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            fn (string $sql) => str_contains($sql, $table),
        ));
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
}

it('ships the mode key unset', function () {
    expect(require __DIR__.'/../../config/concierge.php')->toHaveKey('mode', null);
});

it('reads a missing mode key as app-wide', function () {
    config()->set('concierge.models.workspace', null);
    config()->offsetUnset('concierge.mode');

    expect(Mode::configured())->toBe(Mode::App)
        ->and(Mode::inferred())->toBeFalse();
});

it('reads a missing mode key with a workspace model as workspace mode until 1.0', function () {
    config()->offsetUnset('concierge.mode');

    expect(config('concierge.models.workspace'))->toBe(Workspace::class)
        ->and(Mode::configured())->toBe(Mode::Workspaces)
        ->and(Mode::inferred())->toBeTrue();
});

it('reads an explicit mode over the workspace model', function (string $value, Mode $mode) {
    config()->set('concierge.mode', $value);

    expect(Mode::configured())->toBe($mode)
        ->and(Mode::inferred())->toBeFalse();
})->with([
    'app' => ['app', Mode::App],
    'workspaces' => ['workspaces', Mode::Workspaces],
]);

it('refuses an unknown mode, naming the valid values', function (mixed $value, string $got) {
    config()->set('concierge.mode', $value);

    expect(fn () => Mode::configured())
        ->toThrow(UnsupportedConfiguration::class, "concierge.mode must be one of [app, workspaces]; got {$got}.");
})->with([
    'a typo' => ['workspace', '[workspace]'],
    'the wrong case' => ['App', '[App]'],
    'a boolean' => [true, 'bool'],
]);

it('refuses an unknown mode while the provider registers', function () {
    config()->set('concierge.mode', 'teams');

    expect(fn () => app()->register(ConciergeServiceProvider::class, force: true))
        ->toThrow(UnsupportedConfiguration::class, 'got [teams]');
});

it('shows the mode in about', function () {
    $this->artisan('about', ['--only' => 'concierge'])
        ->expectsOutputToContain('workspaces')
        ->doesntExpectOutputToContain('Deprecated')
        ->assertSuccessful();
});

it('shows a deprecation line in about when the mode is inferred', function () {
    config()->offsetUnset('concierge.mode');

    $this->artisan('about', ['--only' => 'concierge'])
        ->expectsOutputToContain("Mode inferred from models.workspace; set 'mode' => 'workspaces' in config/concierge.php before 1.0")
        ->assertSuccessful();
});

it('refuses app-wide mode until it is implemented, naming the issue', function () {
    $owner = host_user('owner');
    $workspace = host_workspace();
    config()->set('concierge.mode', 'app');

    $refusal = "Concierge does not support concierge.mode = 'app' yet (teylabs/concierge#19)";

    expect(fn () => ConciergeModels::assertModeSupported())->toThrow(UnsupportedConfiguration::class, $refusal)
        ->and(fn () => SyncRoleDefinitions::run())->toThrow(UnsupportedConfiguration::class, $refusal)
        ->and(fn () => EstablishWorkspace::run($workspace, $owner))->toThrow(UnsupportedConfiguration::class, $refusal)
        ->and(fn () => app(WorkspaceContext::class)->run($workspace, fn () => null))->toThrow(UnsupportedConfiguration::class, $refusal);
});

it('reads roles without the teams key when Spatie teams are off', function () {
    $owner = host_user('owner');
    $workspace = host_workspace(owner: $owner);
    app(PermissionRegistrar::class)->teams = false;

    $role = null;
    $find = queries_against('roles', function () use (&$role) {
        $role = Roles::find('owner');
    });

    $names = null;
    $directory = queries_against('model_has_roles', function () use (&$names, $owner, $workspace) {
        $names = app(Concierge::class)->directory($workspace, $owner)->roleNames($owner->membershipIn($workspace));
    });

    expect($role?->name)->toBe('owner')
        ->and($names)->toBe(['owner'])
        ->and($find)->not->toBeEmpty()
        ->and($directory)->toHaveCount(1)
        ->and(implode(' ', [...$find, ...$directory]))->not->toContain('workspace_id');
});

it('applies the configured rank rules to role names', function () {
    expect(RankRules::grantable(['owner']))->toBe(['admin', 'presenter'])
        ->and(RankRules::grantable(['admin']))->toBe(['presenter'])
        ->and(RankRules::grantable(['presenter']))->toBe([])
        ->and(RankRules::manages(['admin']))->toBeTrue()
        ->and(RankRules::manages(['presenter']))->toBeFalse()
        ->and(RankRules::outranks(['admin'], ['presenter']))->toBeTrue()
        ->and(RankRules::outranks(['admin'], ['admin']))->toBeFalse()
        ->and(RankRules::outranks(['presenter'], ['admin']))->toBeFalse()
        ->and(RankRules::outranks([], []))->toBeTrue()
        ->and(RankRules::outranks([], ['presenter']))->toBeFalse();
});
