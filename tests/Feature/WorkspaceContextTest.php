<?php

use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tey\Concierge\Actions\SuspendMembership;
use Tey\Concierge\Contracts\WorkspaceContext;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Support\ConciergeModels;
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

it('fails closed in middleware: 404 without context, 403 unless active', function () {
    $context = app(WorkspaceContext::class);
    Route::middleware('concierge.member')->get('/workspace-probe', fn () => 'ok');
    Route::middleware(['concierge.member'])->get('/acme-probe', fn () => 'ok');

    $this->actingAs($this->alice)->get('/workspace-probe')->assertNotFound();

    $call = fn (User $user) => $context->run($this->acme, fn () => $this->actingAs($user)->get('/acme-probe'));

    $call($this->alice)->assertOk();
    $call(host_user('stranger'))->assertForbidden();

    SuspendMembership::run($this->owner, $this->alice->membershipIn($this->acme));

    $call($this->alice)->assertForbidden();
});

it('refuses unsupported configuration', function () {
    config()->set('permission.teams', false);
    expect(fn () => ConciergeModels::assertModeSupported())->toThrow(UnsupportedConfiguration::class, 'requires permission.teams = true');

    config()->set('concierge.models.workspace', UuidWorkspace::class);
    expect(fn () => ConciergeModels::workspace())->toThrow(UnsupportedConfiguration::class, 'incrementing integer key');

    config()->set('concierge.models.user', stdClass::class);
    expect(fn () => ConciergeModels::user())->toThrow(UnsupportedConfiguration::class);
});
