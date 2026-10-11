<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tey\Concierge\Concierge;
use Tey\Concierge\ConciergeServiceProvider;
use Tey\Concierge\Contracts\WorkspaceContext;
use Tey\Concierge\Enums\Mode;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Facades\Concierge as ConciergeFacade;
use Tey\Concierge\Support\AppScope;
use Tey\Concierge\Support\ConciergeModels;
use Workbench\App\Models\Workspace;

it('creates only the tables app-wide mode uses', function () {
    expect(Schema::hasTable('concierge_invitations'))->toBeTrue()
        ->and(Schema::hasTable('concierge_accounts'))->toBeTrue()
        ->and(Schema::hasTable('concierge_memberships'))->toBeFalse()
        ->and(Schema::hasTable('concierge_ownerships'))->toBeFalse()
        ->and(Schema::hasTable('concierge_tenant_invitations'))->toBeFalse()
        ->and(Schema::hasColumn('roles', 'workspace_id'))->toBeFalse()
        ->and(Schema::hasColumn('model_has_roles', 'workspace_id'))->toBeFalse();

    $indexes = collect(Schema::getIndexes('concierge_invitations'));

    expect($indexes->first(fn (array $index) => $index['columns'] === ['pending_key'])['unique'])->toBeTrue()
        ->and($indexes->contains(fn (array $index) => $index['columns'] === ['status']))->toBeTrue()
        ->and($indexes->contains(fn (array $index) => $index['columns'] === ['email']))->toBeTrue()
        ->and(collect(Schema::getForeignKeys('concierge_invitations'))->pluck('columns')->flatten()->all())->not->toContain('workspace_id');

    $accounts = collect(Schema::getForeignKeys('concierge_accounts'))->mapWithKeys(fn (array $fk) => [$fk['columns'][0] => $fk['foreign_table']])->sortKeys();

    expect($accounts->all())->toBe(['deactivated_by_id' => 'users', 'invitation_id' => 'concierge_invitations', 'user_id' => 'users']);
});

it('reports the mode on the facade and binds the app scope as the workspace context', function () {
    expect(ConciergeFacade::mode())->toBe(Mode::App)
        ->and(app(Concierge::class)->mode())->toBe(Mode::App)
        ->and(app(WorkspaceContext::class))->toBeInstanceOf(AppScope::class)
        ->and(app(WorkspaceContext::class)->current())->toBeNull();

    $workspace = new Workspace(['name' => 'Acme']);

    expect(fn () => app(WorkspaceContext::class)->run($workspace, fn () => null))
        ->toThrow(UnsupportedConfiguration::class, "concierge.mode = 'app' has no workspaces; use the app-wide actions (InviteUser, EstablishOwner, ChangeUserRoles, RemoveUser).");
});

it('requires Spatie teams off and refuses tenant invitations at boot', function () {
    ConciergeModels::assertModeSupported();

    config()->set('permission.teams', true);

    expect(fn () => ConciergeModels::assertModeSupported())
        ->toThrow(UnsupportedConfiguration::class, 'Concierge app-wide mode requires permission.teams = false');

    config()->set('permission.teams', false);
    config()->set('concierge.features.tenant_invitations', true);

    expect(fn () => app()->register(ConciergeServiceProvider::class, force: true))
        ->toThrow(UnsupportedConfiguration::class, "concierge.features.tenant_invitations needs concierge.mode = 'workspaces'; the mode is 'app'.");
});

it('syncs plain global roles with no team column', function () {
    $this->artisan('concierge:sync-roles')->assertSuccessful();

    expect(DB::table('roles')->pluck('name')->sort()->values()->all())->toBe(['admin', 'owner', 'presenter']);

    $this->artisan('concierge:sync-roles')->assertSuccessful();

    expect(DB::table('roles')->count())->toBe(3);
});
