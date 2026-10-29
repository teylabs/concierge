<?php

namespace Tey\Concierge\Tests\Routes;

use Tey\Concierge\Tests\AppMode\AppModeTestCase;

/** An app-wide app set up by concierge:install, with concierge.routes.enabled true. */
class AppModeRoutesTestCase extends AppModeTestCase
{
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('concierge.routes', ['enabled' => true]);
    }
}
