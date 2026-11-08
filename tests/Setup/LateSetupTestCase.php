<?php

namespace Tey\Concierge\Tests\Setup;

use Tey\Concierge\Tests\Setup\Fixtures\LateSetupProvider;

/**
 * A fresh app with no config/concierge.php and no setup switch of its own, where
 * a package registered after concierge sets concierge.setup while it registers.
 */
class LateSetupTestCase extends NotSetUpTestCase
{
    protected function getPackageProviders($app)
    {
        return [...parent::getPackageProviders($app), LateSetupProvider::class];
    }
}
