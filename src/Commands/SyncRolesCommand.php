<?php

namespace Tey\Concierge\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Tey\Concierge\Actions\SyncRoleDefinitions;
use Tey\Concierge\Exceptions\UnsupportedConfiguration;
use Tey\Concierge\Support\ConciergeModels;
use Tey\Concierge\Support\Roles;

class SyncRolesCommand extends Command
{
    public $signature = 'concierge:sync-roles
        {--dry-run : Preview missing roles without writing or clearing the permission cache}
        {--json : Print the outcome for each configured role as JSON}';

    public $description = 'Ensure the configured Concierge roles exist as global Spatie roles.';

    public function handle(): int
    {
        try {
            ConciergeModels::assertModeSupported();
            $results = [];

            foreach (Roles::configured() as $name) {
                $results[] = [
                    'name' => $name,
                    'guard' => Roles::guard(),
                    'outcome' => Roles::find($name) ? 'already_exists' : ($this->option('dry-run') ? 'would_create' : 'created'),
                ];
            }

            if (! $this->option('dry-run')) {
                SyncRoleDefinitions::run();
            }
        } catch (UnsupportedConfiguration|QueryException $exception) {
            $message = $exception->getMessage();

            if ($exception instanceof QueryException) {
                $message .= ' Check database.default in config/database.php and run php artisan migrate before retrying php artisan concierge:sync-roles.';
            }

            if ($this->option('json')) {
                $this->line((string) json_encode(['error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif ($results === []) {
            $this->components->info('No configured roles; nothing to do.');
        } else {
            foreach ($results as $result) {
                $this->line("{$result['name']} ({$result['guard']}): ".str_replace('_', ' ', $result['outcome']));
            }
        }

        return self::SUCCESS;
    }
}
