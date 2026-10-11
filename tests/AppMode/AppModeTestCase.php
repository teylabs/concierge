<?php

namespace Tey\Concierge\Tests\AppMode;

use Illuminate\Contracts\Config\Repository;
use Tey\Concierge\Tests\TestCase;

/**
 * The app-wide leg of the matrix: concierge.mode = 'app', Spatie teams off,
 * no workspace model, tenant invitations off (they need workspaces).
 *
 * Pest binds this case to tests/AppMode with DatabaseMigrations rather than
 * RefreshDatabase (see tests/Pest.php): the schema differs from the workspace
 * leg's, and on a shared PostgreSQL database DatabaseMigrations re-migrates
 * before each test and marks the database unmigrated afterwards, so whichever
 * leg runs next starts from its own schema.
 */
class AppModeTestCase extends TestCase
{
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('concierge.features', ['membership' => true, 'tenant_invitations' => false]);
        $app['config']->set('concierge.mode', 'app');
        $app['config']->set('concierge.models.workspace', null);
    }

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        // The base case sets the workspace leg's values again here, so repeat ours.
        tap($app['config'], function (Repository $config) {
            $config->set('concierge.mode', 'app');
            $config->set('concierge.models.workspace', null);
            $config->set('permission.teams', false);
        });
    }
}
