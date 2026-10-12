<?php

namespace Tey\Concierge\Tests\Fortify;

use Illuminate\Contracts\Config\Repository;

/**
 * A Fortify host in app-wide mode: no workspace model, Spatie teams off,
 * tenant invitations off. Like AppModeTestCase, it migrates its own schema.
 */
class FortifyAppWideTestCase extends FortifyTestCase
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

        tap($app['config'], function (Repository $config) {
            $config->set('concierge.mode', 'app');
            $config->set('concierge.models.workspace', null);
            $config->set('permission.teams', false);
        });
    }
}
