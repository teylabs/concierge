<?php

namespace Tey\Concierge\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Permission\PermissionServiceProvider;
use Tey\Concierge\ConciergeServiceProvider;
use Workbench\App\Models\User;
use Workbench\App\Models\Workspace;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Tey\\Concierge\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            PermissionServiceProvider::class,
            ConciergeServiceProvider::class,
        ];
    }

    public static function usesPostgres(): bool
    {
        return getenv('CONCIERGE_TEST_DB') === 'pgsql';
    }

    /**
     * The package reads its feature switch while the provider registers, before
     * defineEnvironment() runs, so a test host sets it with the "host config".
     */
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('concierge.features', ['membership' => true, 'tenant_invitations' => true]);
    }

    protected function defineEnvironment($app)
    {
        tap($app['config'], function (Repository $config) {
            if (static::usesPostgres()) {
                $config->set('database.connections.pgsql', [
                    'driver' => 'pgsql',
                    'host' => getenv('CONCIERGE_TEST_DB_HOST') ?: '127.0.0.1',
                    'port' => getenv('CONCIERGE_TEST_DB_PORT') ?: '5432',
                    'database' => 'concierge_test_core',
                    'username' => getenv('CONCIERGE_TEST_DB_USERNAME') ?: 'root',
                    'password' => getenv('CONCIERGE_TEST_DB_PASSWORD') ?: '',
                    'charset' => 'utf8',
                    'prefix' => '',
                    'search_path' => 'public',
                    'sslmode' => 'prefer',
                ]);
                $config->set('database.default', 'pgsql');
            } else {
                $config->set('database.default', 'testing');
                $config->set('database.connections.testing.foreign_key_constraints', true);
            }

            $config->set('auth.providers.users.model', User::class);
            $config->set('permission.teams', true);
            $config->set('permission.column_names.team_foreign_key', 'workspace_id');
            $config->set('concierge.models.user', User::class);
            $config->set('concierge.models.workspace', Workspace::class);
            $config->set('concierge.tenant_invitations.platform_owners', ['platform@example.test']);
            $config->set('queue.default', 'sync');
            $config->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        });
    }

    protected function defineDatabaseMigrations()
    {
        $this->app->make('migrator')->path(__DIR__.'/../workbench/database/migrations');
    }
}
