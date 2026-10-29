<?php

namespace Tey\Concierge\Tests\Routes;

use Tey\Concierge\Tests\TestCase;

/**
 * An app set up by concierge:install in workspace mode on concierge's own
 * engine (Spatie teams on): concierge.routes.enabled is true, so the
 * concierge.* routes are registered while the provider boots.
 */
class RoutesTestCase extends TestCase
{
    /** Extra host config, read while the provider boots. */
    protected array $routesConfig = [];

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('concierge.routes', ['enabled' => true, ...$this->routesConfig]);
    }
}
