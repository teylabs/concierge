<?php

namespace Tey\Concierge\Tests\Setup;

use Tey\Concierge\Tests\Setup\Fixtures\HostBindingProvider;
use Tey\Concierge\Tests\TestCase;

class HostBindingTestCase extends TestCase
{
    protected function getPackageProviders($app)
    {
        HostBindingProvider::$concierge = HostBindingProvider::$localLogin = null;

        return [...parent::getPackageProviders($app), HostBindingProvider::class];
    }
}
