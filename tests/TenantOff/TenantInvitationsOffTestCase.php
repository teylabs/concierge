<?php

namespace Tey\Concierge\Tests\TenantOff;

use Tey\Concierge\Tests\TestCase;

/**
 * An existing 0.2 host: membership on, tenant invitations never mentioned.
 */
class TenantInvitationsOffTestCase extends TestCase
{
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('concierge.features', ['membership' => true]);
    }
}
