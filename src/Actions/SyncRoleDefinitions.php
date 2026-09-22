<?php

namespace Tey\Concierge\Actions;

use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Permission\PermissionRegistrar;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\Roles;

class SyncRoleDefinitions
{
    use AsAction;

    public string $commandSignature = 'concierge:sync-roles';

    public string $commandDescription = 'Ensure the configured Concierge roles exist as global Spatie roles.';

    public function handle(): void
    {
        ConciergeModels::assertTeamsEnabled();

        $registrar = app(PermissionRegistrar::class);

        foreach (Roles::configured() as $name) {
            if (! Roles::find($name)) {
                $registrar->getRoleClass()::create([
                    'name' => $name,
                    'guard_name' => Roles::guard(),
                    $registrar->teamsKey => null,
                ]);
            }
        }

        $registrar->forgetCachedPermissions();
    }

    public function asCommand(): void
    {
        $this->handle();
    }
}
