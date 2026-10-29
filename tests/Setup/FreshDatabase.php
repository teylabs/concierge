<?php

namespace Tey\Concierge\Tests\Setup;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;

/**
 * A setup test starts from an empty database: on PostgreSQL the test database
 * outlives a test, so tables other tests migrated would still be there. The
 * wipe invalidates RefreshDatabase's "already migrated" state, which is reset
 * so the next test that needs the schema migrates it again.
 */
trait FreshDatabase
{
    protected function setUpFreshDatabase(): void
    {
        Schema::dropAllTables();
    }

    protected function tearDownFreshDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
    }
}
