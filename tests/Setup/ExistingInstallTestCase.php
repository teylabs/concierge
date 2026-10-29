<?php

namespace Tey\Concierge\Tests\Setup;

use Tey\Concierge\Tests\TestCase;

/**
 * An app that used concierge before 0.10: it has its own config/concierge.php
 * and has never heard of the setup switch.
 */
class ExistingInstallTestCase extends TestCase
{
    use OwnConfigPath;

    protected function resolveApplicationConfiguration($app)
    {
        $this->useOwnConfigPath($app, withConciergeConfig: true);

        parent::resolveApplicationConfiguration($app);

        $app['config']->offsetUnset('concierge.setup');
    }
}
