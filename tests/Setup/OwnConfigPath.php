<?php

namespace Tey\Concierge\Tests\Setup;

use Illuminate\Filesystem\Filesystem;

/**
 * Setup is decided by whether config/concierge.php exists, and other tests
 * publish that file into the shared Testbench skeleton. A setup test therefore
 * reads its config from its own copy of the skeleton's config directory, so it
 * is safe under `pest --parallel`.
 */
trait OwnConfigPath
{
    protected ?string $ownConfigPath = null;

    protected function useOwnConfigPath($app, bool $withConciergeConfig): void
    {
        $files = new Filesystem;
        $this->ownConfigPath = sys_get_temp_dir().'/concierge-setup-'.getmypid().'-'.bin2hex(random_bytes(4));
        $files->copyDirectory($app->configPath(), $this->ownConfigPath);
        $files->delete(array_filter([$this->ownConfigPath.'/concierge.php'], 'file_exists'));

        if ($withConciergeConfig) {
            $files->put($this->ownConfigPath.'/concierge.php', "<?php\n\nreturn [];\n");
        }

        $app->useConfigPath($this->ownConfigPath);
    }

    /** Call from tearDown(), after parent::tearDown(). */
    protected function deleteOwnConfigPath(): void
    {
        if ($this->ownConfigPath !== null) {
            (new Filesystem)->deleteDirectory($this->ownConfigPath);
            $this->ownConfigPath = null;
        }
    }
}
