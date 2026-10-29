<?php

namespace Tey\Concierge\Tests\Scaffolding;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Tey\Concierge\Tests\TestCase;

/**
 * The testbench skeleton plays the host app. Mod reads the app's stack
 * (package.json, tsconfig.json) when its layout compiles at boot, so the
 * stack files are written before the application is created and removed after.
 * Each test process works in its own copy of the skeleton, so scaffolding tests
 * in parallel workers never see each other's files.
 */
class ScaffoldTestCase extends TestCase
{
    /** @var array<string, string> the host's frontend dependencies */
    protected static array $frontend = ['@inertiajs/vue3' => '^3.0'];

    protected static bool $typescript = true;

    public const PAGES = 'resources/js/pages/Concierge';

    private static ?string $skeleton = null;

    public static function applicationBasePath()
    {
        if (self::$skeleton === null) {
            self::$skeleton = sys_get_temp_dir().'/concierge-scaffold-'.getmypid().'-'.bin2hex(random_bytes(4));
            (new Filesystem)->copyDirectory(parent::applicationBasePath(), self::$skeleton);
            register_shutdown_function(fn () => (new Filesystem)->deleteDirectory((string) self::$skeleton));
        }

        return self::$skeleton;
    }

    protected function setUp(): void
    {
        $host = static::applicationBasePath();
        foreach (['package.json', 'tsconfig.json', 'resources/js'] as $path) {
            if (file_exists($host.'/'.$path)) {
                static::fail("The skeleton already has {$path}; the test cannot own it.");
            }
        }
        // Plain file calls: the application, and so the File facade, does not exist yet.
        file_put_contents($host.'/package.json', json_encode(['devDependencies' => static::$frontend]));
        if (static::$typescript) {
            file_put_contents($host.'/tsconfig.json', '{}');
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $host = static::applicationBasePath();
        File::delete([$host.'/package.json', $host.'/tsconfig.json']);
        File::deleteDirectory($host.'/resources/js');

        parent::tearDown();
    }
}
