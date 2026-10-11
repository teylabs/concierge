<?php

namespace Tey\Concierge\Tests\Kit;

use Illuminate\Contracts\Config\Repository;
use Tey\Concierge\Tests\TestCase;
use Workbench\App\Kit\Team;
use Workbench\App\Kit\User;

/**
 * A Laravel starter kit app with teams (laravel new --teams): the kit's users,
 * teams, team_members and team_invitations tables, Spatie teams off, and
 * concierge on the kit engine.
 *
 * Always SQLite: on the shared PostgreSQL database whichever class migrates
 * first decides the schema, and this schema differs from the core suite's.
 */
class KitTestCase extends TestCase
{
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('concierge.workspaces.engine', 'kit');
    }

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        tap($app['config'], function (Repository $config) {
            $config->set('database.default', 'testing');
            $config->set('database.connections.testing.foreign_key_constraints', true);
            $config->set('permission.teams', false);
            $config->set('auth.providers.users.model', User::class);
            $config->set('concierge.models.user', User::class);
            $config->set('concierge.models.workspace', Team::class);
        });
    }

    protected function defineDatabaseMigrations()
    {
        $this->app->make('migrator')->path(__DIR__.'/../../workbench/database/kit-migrations');
    }
}
