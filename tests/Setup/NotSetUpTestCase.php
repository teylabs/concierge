<?php

namespace Tey\Concierge\Tests\Setup;

use Tey\Concierge\Tests\TestCase;

/**
 * A fresh app straight after `composer require tey/concierge`: no
 * config/concierge.php and no setup switch. Local login is turned on only to
 * prove that even an enabled feature stays unregistered.
 */
class NotSetUpTestCase extends TestCase
{
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->offsetUnset('concierge.setup');
        $app['config']->set('concierge.local_login.enabled', true);
    }
}
