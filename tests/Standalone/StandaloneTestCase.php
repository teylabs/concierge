<?php

namespace Tey\Concierge\Tests\Standalone;

use Illuminate\Contracts\Config\Repository;
use Tey\Concierge\ConciergeServiceProvider;
use Tey\Concierge\Tests\TestCase;
use Workbench\App\Models\LocalAuth\PlainAccount;

/**
 * A host that wants only local login: membership off, the Concierge provider
 * alone (no Spatie permission or Actions providers), a bare accounts table, and
 * none of the concierge.models, permission or workspace configuration.
 *
 * Pest binds this case to tests/Standalone only (see tests/Pest.php).
 */
class StandaloneTestCase extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [ConciergeServiceProvider::class];
    }

    /**
     * The host's config/concierge.php is loaded before any provider registers, and the
     * Concierge provider reads the membership switch while it registers. Testbench's
     * defineEnvironment() runs after providers register, so set it here instead.
     */
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('concierge.features.membership', false);
    }

    protected function defineEnvironment($app)
    {
        tap($app['config'], function (Repository $config) {
            $config->set('database.default', 'testing');
            $config->set('auth.providers.users.model', PlainAccount::class);
            $config->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        });
    }

    protected function defineDatabaseMigrations()
    {
        $this->loadMigrationsFrom(__DIR__.'/migrations');
    }
}
