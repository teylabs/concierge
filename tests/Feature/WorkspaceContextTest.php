<?php

use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tey\Concierge\Actions\LeaveWorkspace;
use Tey\Concierge\Actions\RemoveMembership;
use Tey\Concierge\Actions\SuspendMembership;
use Tey\Concierge\Contracts\WorkspaceContext;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\CurrentWorkspace;
use Tey\Concierge\Support\Roles;
use Workbench\App\Models\User;
use Workbench\App\Models\UuidWorkspace;

beforeEach(function () {
    $this->owner = host_user('owner');
    $this->acme = host_workspace('Acme', $this->owner);
    $this->globex = host_workspace('Globex', host_user('globex-owner'));
    $this->alice = host_user('alice');
    join_as($this->acme, $this->owner, $this->alice, ['admin']);
    join_as($this->globex, $this->globex->owner()->user, $this->alice, ['presenter']);
    Role::query()->whereNull('workspace_id')->where('name', 'admin')->first()
        ->givePermissionTo(Permission::create(['name' => 'members.manage', 'guard_name' => 'web']));
});

it('restores the previous team and drops cached relations, even on failure', function () {
    $context = app(WorkspaceContext::class);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->globex->id);
    $this->actingAs($this->alice);
    $this->alice->load('roles');

    expect(fn () => $context->run($this->acme, function () use ($context) {
        expect($context->current()->is($this->acme))->toBeTrue()
            ->and($this->alice->relationLoaded('roles'))->toBeFalse()
            ->and($this->alice->hasRole('admin'))->toBeTrue()
            ->and($this->alice->can('members.manage'))->toBeTrue();

        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class, 'boom');

    expect($registrar->getPermissionsTeamId())->toBe($this->globex->id)
        ->and($context->current())->toBeNull()
        ->and($this->alice->relationLoaded('roles'))->toBeFalse()
        ->and($this->alice->hasRole('admin'))->toBeFalse()
        ->and($this->alice->hasRole('presenter'))->toBeTrue()
        ->and($this->alice->can('members.manage'))->toBeFalse();
});

it('grants nothing without a team context', function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    expect($this->alice->fresh()->hasRole('admin'))->toBeFalse()
        ->and($this->alice->fresh()->can('members.manage'))->toBeFalse();
});

it('nests contexts and restores each level', function () {
    $context = app(WorkspaceContext::class);

    $context->run($this->acme, function () use ($context) {
        $context->run($this->globex, fn () => expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe($this->globex->id));

        expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe($this->acme->id)
            ->and($context->current()->is($this->acme))->toBeTrue();
    });

    expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBeNull();
});

it('enters a workspace until the matching leave, across calls, and restores each level', function () {
    $context = app(WorkspaceContext::class);
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($this->globex->id);
    $this->actingAs($this->alice);
    $this->alice->load('roles');

    $context->enter($this->acme);
    expect($context->current()->is($this->acme))->toBeTrue()
        ->and($registrar->getPermissionsTeamId())->toBe($this->acme->id)
        ->and($this->alice->relationLoaded('roles'))->toBeFalse()
        ->and($this->alice->hasRole('admin'))->toBeTrue();

    $context->run($this->globex, fn () => expect($context->current()->is($this->globex))->toBeTrue());
    expect($context->current()->is($this->acme))->toBeTrue()
        ->and($registrar->getPermissionsTeamId())->toBe($this->acme->id);

    $context->leave();
    expect($context->current())->toBeNull()
        ->and($registrar->getPermissionsTeamId())->toBe($this->globex->id)
        ->and($this->alice->relationLoaded('roles'))->toBeFalse()
        ->and($this->alice->hasRole('presenter'))->toBeTrue()
        ->and($this->alice->hasRole('admin'))->toBeFalse();
});

it('refuses to leave with nothing entered, leaving the team as it was', function () {
    $context = app(WorkspaceContext::class);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->globex->id);

    expect(fn () => $context->leave())->toThrow(LogicException::class, 'There is no workspace context to leave.')
        ->and(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe($this->globex->id);
});

it('fails closed in middleware: 404 without context or a membership, the no-workspace route once it is unavailable', function () {
    $context = app(WorkspaceContext::class);
    Route::middleware('concierge.member')->get('/workspace-probe', fn () => 'ok');
    Route::middleware(['concierge.member'])->get('/acme-probe', fn () => 'ok');

    $this->actingAs($this->alice)->get('/workspace-probe')->assertNotFound();

    $call = fn (User $user, array $headers = []) => $context->run($this->acme, fn () => $this->actingAs($user)->get('/acme-probe', $headers));

    $call($this->alice)->assertOk();
    $call(host_user('stranger'))->assertNotFound();

    SuspendMembership::run($this->owner, $this->alice->membershipIn($this->acme));

    $call($this->alice)->assertRedirect('/');
    $call($this->alice, ['Accept' => 'application/json'])->assertForbidden();

    config()->set('concierge.workspaces.no_workspace_url', '/no-workspace');
    $call($this->alice)->assertRedirect('/no-workspace');
});

it('repoints the current workspace after remove, leave or suspend', function (string $how) {
    $current = app(CurrentWorkspace::class);
    $this->actingAs($this->alice);
    $current->remember($this->acme);

    expect($current->resolve($this->alice)?->is($this->acme))->toBeTrue();

    $membership = $this->alice->membershipIn($this->acme);
    match ($how) {
        'remove' => RemoveMembership::run($this->owner, $membership),
        'leave' => LeaveWorkspace::run($this->alice, $this->acme),
        'suspend' => SuspendMembership::run($this->owner, $membership),
    };

    expect($current->resolve($this->alice)?->is($this->globex))->toBeTrue()
        ->and($current->id())->toBe($this->globex->id);

    RemoveMembership::run($this->globex->owner()->user, $this->alice->membershipIn($this->globex));

    expect($current->resolve($this->alice))->toBeNull()
        ->and($current->id())->toBeNull();
})->with(['remove', 'leave', 'suspend']);

it('never remembers a workspace the user is not an active member of', function () {
    $current = app(CurrentWorkspace::class);
    $this->actingAs($stranger = host_user('stranger'));
    $current->remember($this->acme);

    expect($current->resolve($stranger))->toBeNull();
});

it('refuses unsupported configuration', function () {
    config()->set('permission.teams', false);
    expect(fn () => ConciergeModels::assertModeSupported())->toThrow(UnsupportedConfiguration::class, "requires permission.teams = true; set 'teams' => true in config/permission.php (on an existing schema, run php artisan permission:setup-teams and migrate)");

    expect(fn () => Roles::resolve(['auditor']))
        ->toThrow(UnsupportedConfiguration::class, "Role [auditor] is not in concierge.roles; add it to 'roles' in config/concierge.php, then run php artisan concierge:sync-roles.");

    Role::query()->whereNull('workspace_id')->where('name', 'presenter')->delete();
    expect(fn () => Roles::resolve(['presenter']))
        ->toThrow(UnsupportedConfiguration::class, 'Role [presenter] is not defined; run php artisan concierge:sync-roles');

    config()->set('concierge.models.workspace', UuidWorkspace::class);
    expect(fn () => ConciergeModels::workspace())->toThrow(UnsupportedConfiguration::class, 'incrementing integer key; remove HasUuids or HasUlids');

    config()->set('concierge.models.user', stdClass::class);
    expect(fn () => ConciergeModels::user())->toThrow(UnsupportedConfiguration::class, 'concierge.models.user must be an Eloquent model implementing [Tey\\Concierge\\Contracts\\Member]; set it in config/concierge.php');
});
