<?php

namespace Tey\Concierge\Tests\Routes;

use Tey\Concierge\Tests\Kit\KitTestCase;

/** A starter kit app with teams on the kit engine, with concierge.routes.enabled true. */
class KitRoutesTestCase extends KitTestCase
{
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('concierge.routes', ['enabled' => true]);
    }
}
