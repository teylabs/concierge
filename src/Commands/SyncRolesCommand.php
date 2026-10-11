<?php

namespace Tey\Concierge\Commands;

use Illuminate\Console\Command;
use Tey\Concierge\Actions\SyncRoleDefinitions;

class SyncRolesCommand extends Command
{
    public $signature = 'concierge:sync-roles';

    public $description = 'Ensure the configured Concierge roles exist as global Spatie roles.';

    public function handle(): void
    {
        SyncRoleDefinitions::run();
    }
}
