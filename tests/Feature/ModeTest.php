<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tey\Concierge\Actions\ChangeUserRoles;
use Tey\Concierge\Actions\EstablishOwner;
use Tey\Concierge\Actions\EstablishWorkspace;
use Tey\Concierge\Actions\InviteUser;
use Tey\Concierge\Actions\RemoveUser;
use Tey\Concierge\Actions\SyncRoleDefinitions;
use Tey\Concierge\Actions\UpdateUser;
use Tey\Concierge\Concierge;
use Tey\Concierge\ConciergeServiceProvider;
use Tey\Concierge\Contracts\WorkspaceContext;
use Tey\Concierge\Enums\AccountStatus;
use Tey\Concierge\Enums\Mode;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Models\Account;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\GrantSet;
use Tey\Concierge\Support\RankRules;
use Tey\Concierge\Support\Roles;
use Workbench\App\Models\User;
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
        ->toThrow(UnsupportedConfiguration::class, "concierge.mode must be one of [app, workspaces]; got {$got}. Set 'mode' in config/concierge.php to one of them.");
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

it('requires Spatie teams to match the mode', function () {
    $owner = host_user('owner');
    $workspace = host_workspace();
    config()->set('concierge.mode', 'app');

    $refusal = "Concierge app-wide mode requires permission.teams = false; set 'teams' => false in config/permission.php";

    expect(fn () => ConciergeModels::assertModeSupported())->toThrow(UnsupportedConfiguration::class, $refusal)
        ->and(fn () => SyncRoleDefinitions::run())->toThrow(UnsupportedConfiguration::class, $refusal)
        ->and(fn () => EstablishWorkspace::run($workspace, $owner))->toThrow(UnsupportedConfiguration::class, "concierge.mode = 'app' has no workspaces; use EstablishOwner.")
        ->and(fn () => app(WorkspaceContext::class)->run($workspace, fn () => null))->toThrow(UnsupportedConfiguration::class, $refusal);

    config()->set('concierge.mode', 'workspaces');
    config()->set('permission.teams', false);

    expect(fn () => ConciergeModels::assertModeSupported())->toThrow(UnsupportedConfiguration::class, 'requires permission.teams = true');
});

it('keeps the workspace-mode schema as it was before app-wide mode', function () {
    $shape = fn (string $table) => [
        'columns' => collect(Schema::getColumns($table))->map(fn (array $c) => $c['name'].':'.($c['nullable'] ? 'null' : 'not null'))->all(),
        'indexes' => collect(Schema::getIndexes($table))->map(fn (array $i) => implode(',', $i['columns']).($i['unique'] ? ' unique' : ''))->sort()->values()->all(),
        'foreign' => collect(Schema::getForeignKeys($table))->map(fn (array $f) => implode(',', $f['columns']).' -> '.$f['foreign_table'].'('.implode(',', $f['foreign_columns']).') '.$f['on_delete'])->sort()->values()->all(),
    ];

    expect($shape('concierge_memberships'))->toBe([
        'columns' => ['id:not null', 'workspace_id:not null', 'user_id:not null', 'status:not null', 'invitation_id:null', 'joined_at:null', 'suspended_at:null', 'suspended_by_id:null', 'suspension_reason:null', 'removed_at:null', 'removed_by_id:null', 'created_at:null', 'updated_at:null'],
        'indexes' => ['id unique', 'user_id,status', 'workspace_id,id unique', 'workspace_id,user_id unique'],
        'foreign' => ['invitation_id -> concierge_invitations(id) set null', 'removed_by_id -> users(id) set null', 'suspended_by_id -> users(id) set null', 'user_id -> users(id) restrict', 'workspace_id -> workspaces(id) restrict'],
    ])->and($shape('concierge_ownerships'))->toBe([
        'columns' => ['workspace_id:not null', 'membership_id:not null', 'previous_membership_id:null', 'transferred_at:null', 'created_at:null', 'updated_at:null'],
        'indexes' => ['membership_id unique', 'workspace_id unique'],
        'foreign' => ['previous_membership_id -> concierge_memberships(id) set null', 'workspace_id -> workspaces(id) restrict', 'workspace_id,membership_id -> concierge_memberships(workspace_id,id) restrict'],
    ])->and($shape('concierge_invitations'))->toBe([
        'columns' => ['id:not null', 'public_id:not null', 'workspace_id:not null', 'email:not null', 'pending_key:null', 'grants:not null', 'grants_fingerprint:not null', 'inviter_id:null', 'token_hash:not null', 'generation:not null', 'rotated_at:null', 'status:not null', 'expires_at:not null', 'last_sent_at:null', 'send_count:not null', 'accepted_at:null', 'accepted_by_id:null', 'accepted_membership_id:null', 'revoked_at:null', 'revoked_by_id:null', 'created_at:null', 'updated_at:null', 'accepted_with_other_email:not null', 'message:null'],
        'indexes' => ['id unique', 'public_id unique', 'token_hash unique', 'workspace_id,email', 'workspace_id,pending_key unique', 'workspace_id,status'],
        'foreign' => ['accepted_by_id -> users(id) set null', 'accepted_membership_id -> concierge_memberships(id) set null', 'inviter_id -> users(id) set null', 'revoked_by_id -> users(id) set null', 'workspace_id -> workspaces(id) restrict'],
    ])->and(Schema::hasTable('concierge_accounts'))->toBeTrue();
});

it('deletes a user\'s accounts row with the user in workspace mode, with no last-owner rule', function () {
    $owner = host_user('owner');
    Account::query()->create(['user_id' => $owner->id, 'status' => AccountStatus::Active]);

    $owner->delete();

    expect(User::query()->find($owner->id))->toBeNull()
        ->and(Account::query()->find($owner->id))->toBeNull();
});

it('refuses the app-wide reads and actions in workspace mode, naming the workspace ones', function () {
    $owner = host_user('owner');

    expect(fn () => app(Concierge::class)->isOwner($owner))->toThrow(UnsupportedConfiguration::class, "concierge.mode = 'workspaces' scopes roles by workspace; isOwner() takes a Membership in workspace mode.")
        ->and(fn () => InviteUser::run($owner, 'a@example.test', new GrantSet(['presenter'])))->toThrow(UnsupportedConfiguration::class, 'use InviteMember')
        ->and(fn () => EstablishOwner::run($owner))->toThrow(UnsupportedConfiguration::class, 'use EstablishWorkspace')
        ->and(fn () => ChangeUserRoles::run($owner, $owner, new GrantSet([])))->toThrow(UnsupportedConfiguration::class, 'use ChangeMemberRoles')
        ->and(fn () => RemoveUser::run($owner, $owner))->toThrow(UnsupportedConfiguration::class, 'use RemoveMembership')
        ->and(fn () => UpdateUser::run($owner, $owner, name: 'Renamed'))->toThrow(UnsupportedConfiguration::class, "UpdateUser is app-wide only; update the user model directly, or set 'mode' => 'app' in config/concierge.php.");
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
