<?php

namespace Tey\Concierge\Actions;

use Spatie\Permission\PermissionRegistrar;
use Tey\Concierge\Concerns\Runnable;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\Roles;

class SyncRoleDefinitions
{
    use Runnable;

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
}
