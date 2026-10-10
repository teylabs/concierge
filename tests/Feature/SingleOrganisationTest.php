<?php

use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;
use Tey\Concierge\Actions\AcceptInvitation;
use Tey\Concierge\Contracts\Workspace;
use Tey\Concierge\Contracts\WorkspaceContext;
use Tey\Concierge\Support\PermissionWorkspaceContext;
use Workbench\App\Models\Workspace as HostWorkspace;

/**
 * A single-organisation host: one ordinary workspace, teams enabled, and a
 * context adapter that always resolves it.
 */
it('runs the full lifecycle for a host with exactly one workspace', function () {
    $owner = host_user('owner');
    $organisation = host_workspace('Acme', $owner);

    app()->scoped(WorkspaceContext::class, fn ($app) => new class($app->make(PermissionRegistrar::class), $app['auth']) extends PermissionWorkspaceContext
    {
        public function current(): ?Workspace
        {
            return parent::current() ?? HostWorkspace::query()->sole();
        }
    });

    $alice = host_user('alice');
    AcceptInvitation::run($alice, issue($owner, $organisation, $alice->email)->token);

    Route::middleware('concierge.member')->get('/dashboard', fn () => 'ok');

    $this->actingAs($alice)->get('/dashboard')->assertOk();
    $this->actingAs(host_user('stranger'))->get('/dashboard')->assertForbidden();
});
