<?php

namespace Tey\Concierge\Tests\Setup;

use Tey\Concierge\Tests\TestCase;

/**
 * An app that used concierge before 0.10: it has its own config/concierge.php
 * and has never heard of the setup switch.
 */
class ExistingInstallTestCase extends TestCase
{
    protected function resolveApplicationConfiguration($app)
    {
        file_put_contents($app->configPath('concierge.php'), "<?php\n\nreturn [];\n");

        parent::resolveApplicationConfiguration($app);

        $app['config']->offsetUnset('concierge.setup');
    }

    protected function tearDown(): void
    {
        @unlink(config_path('concierge.php'));

        parent::tearDown();
    }
}
