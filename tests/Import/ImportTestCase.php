<?php

namespace Tey\Concierge\Tests\Import;

use Tey\Concierge\Tests\TestCase;
use Workbench\App\Models\Organisation;

/**
 * A hand-built app with its own Organisation model and organisation_user pivot,
 * adopted as concierge's workspace under concierge's own engine (D6).
 *
 * Always SQLite, for the same reason as the kit case: its schema differs.
 */
class ImportTestCase extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);
        $app['config']->set('concierge.models.workspace', Organisation::class);
    }

    protected function defineDatabaseMigrations()
    {
        parent::defineDatabaseMigrations();

        $this->app->make('migrator')->path(__DIR__.'/../../workbench/database/import-migrations');
    }
}
