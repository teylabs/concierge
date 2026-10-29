<?php

namespace Tey\Concierge\Tests\Setup;

use Illuminate\Filesystem\Filesystem;
use Tey\Concierge\Install\AppInspector;
use Tey\Concierge\Tests\TestCase;

/**
 * A fresh app straight after `composer require tey/concierge`, with its own
 * base, config and database directories: no config/concierge.php, no Spatie
 * config or migrations, and only the host's users and workspaces tables to
 * migrate. A test writes the app's composer.json or package.json with
 * app_file() before running concierge:install.
 */
class InstallTestCase extends TestCase
{
    use FreshDatabase, OwnConfigPath;

    protected ?string $fixturePath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFreshDatabase();
    }

    protected function resolveApplicationConfiguration($app)
    {
        $files = new Filesystem;
        $this->fixturePath = sys_get_temp_dir().'/concierge-install-'.getmypid().'-'.bin2hex(random_bytes(4));
        $files->ensureDirectoryExists($this->fixturePath.'/database/migrations');
        $files->copy(__DIR__.'/../../workbench/database/migrations/0001_01_01_000000_create_host_tables.php', $this->fixturePath.'/database/migrations/0001_01_01_000000_create_host_tables.php');
        $app->useDatabasePath($this->fixturePath.'/database');

        $this->useOwnConfigPath($app, withConciergeConfig: false);
        $files->delete(array_filter([$app->configPath('permission.php')], 'file_exists'));

        parent::resolveApplicationConfiguration($app);

        $app['config']->set('concierge', []);
        $app['config']->set('permission', require __DIR__.'/../../vendor/spatie/laravel-permission/config/permission.php');
    }

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('concierge', []);
        $app['config']->set('permission', require __DIR__.'/../../vendor/spatie/laravel-permission/config/permission.php');
        $app->instance(AppInspector::class, new AppInspector($this->fixturePath, 'Workbench\\App\\Models\\'));
    }

    protected function defineDatabaseMigrations()
    {
        // concierge:install runs the migrations itself.
    }

    public function appFile(string $name, array $json): void
    {
        file_put_contents($this->fixturePath.'/'.$name, json_encode($json));
    }

    protected function tearDown(): void
    {
        $paths = [$this->fixturePath, $this->ownConfigPath];
        $this->tearDownFreshDatabase();

        parent::tearDown();

        foreach (array_filter($paths) as $path) {
            (new Filesystem)->deleteDirectory($path);
        }
    }
}
