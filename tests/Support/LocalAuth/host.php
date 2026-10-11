<?php

// A throwaway Laravel application on disk, booted by real separate PHP processes, so caches,
// .env files and the process environment behave exactly as they would for a real host.
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Workbench\App\Models\LocalAuth\PlainAccount;

/**
 * The cache files live inside the host; Laravel resolves these relative to it (drive-letter
 * paths are not absolute cache prefixes on Windows).
 *
 * @return array<string, string>
 */
function cache_login_cache_paths(): array
{
    return [
        'APP_CONFIG_CACHE' => 'bootstrap/cache/config.php',
        'APP_ROUTES_CACHE' => 'bootstrap/cache/routes.php',
        'APP_SERVICES_CACHE' => 'bootstrap/cache/services.php',
        'APP_PACKAGES_CACHE' => 'bootstrap/cache/packages.php',
        'APP_EVENTS_CACHE' => 'bootstrap/cache/events.php',
    ];
}

/**
 * One fresh boot through tests/Support/LocalAuth/boot.php. `$environment` is the raw process
 * APP_ENV; null removes an inherited one (false does that even on Windows).
 *
 * @param  array<string, mixed>  $options  see boot.php: superglobal-only sources, runtime putenv, worker steps
 */
function cache_login_process(string $host, string $command, ?string $environment, string $csrf = 'none', string $session = '{}', array $options = []): Process
{
    return new Process(
        [PHP_BINARY, __DIR__.'/boot.php', $host, $command, $csrf, $session, json_encode((object) $options, JSON_THROW_ON_ERROR)],
        null,
        ['APP_ENV' => $environment ?? false, ...cache_login_cache_paths()],
    );
}

/**
 * The host's own `artisan`, exactly as a developer or deploy script runs it.
 *
 * @param  list<string>  $arguments
 */
function cache_login_artisan(string $host, array $arguments, ?string $environment): Process
{
    return new Process([PHP_BINARY, $host.'/artisan', ...$arguments], $host, ['APP_ENV' => $environment ?? false, ...cache_login_cache_paths()]);
}

/**
 * @param  array<string, mixed>  $localLogin  merged over the fixture's local_login configuration
 */
function cache_login_host(string $host, array $localLogin = []): void
{
    $files = new Filesystem;
    foreach (['bootstrap/cache', 'config', 'routes', 'public', 'resources/views', 'storage/framework/sessions', 'storage/framework/views', 'storage/framework/cache', 'storage/logs'] as $path) {
        $files->makeDirectory($host.'/'.$path, 0755, true);
    }
    $autoload = var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true);
    $files->copy(__DIR__.'/app.php', $host.'/bootstrap/app.php');
    $files->put($host.'/composer.json', '{"autoload":{"psr-4":{"App\\\\":"app/"}}}');
    $files->put($host.'/bootstrap/providers.php', '<?php return [];');
    $files->copy(__DIR__.'/routes.php', $host.'/routes/web.php');
    $files->copy(__DIR__.'/console.php', $host.'/routes/console.php');
    $files->put($host.'/artisan', "<?php\nrequire {$autoload};\n\$app = require __DIR__.'/bootstrap/app.php';\nexit(\$app->handleCommand(new Symfony\\Component\\Console\\Input\\ArgvInput));\n");
    $files->put($host.'/public/index.php', "<?php\nrequire {$autoload};\n(require dirname(__DIR__).'/bootstrap/app.php')->handleRequest(Illuminate\\Http\\Request::capture());\n");
    $files->put($host.'/config/app.php', "<?php return ['env' => env('APP_ENV', 'production'), 'key' => 'base64:".base64_encode(str_repeat('k', 32))."', 'cipher' => 'AES-256-CBC', 'url' => 'http://localhost'];");
    $configs = [
        'auth' => ['defaults' => ['guard' => 'web', 'passwords' => 'users'], 'guards' => ['web' => ['driver' => 'session', 'provider' => 'users']], 'providers' => ['users' => ['driver' => 'eloquent', 'model' => PlainAccount::class]]],
        'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => $host.'/database.sqlite', 'prefix' => '']]],
        'session' => ['driver' => 'file', 'files' => $host.'/storage/framework/sessions', 'cookie' => 'cache_probe_session', 'lifetime' => 120, 'lottery' => [0, 100], 'path' => '/', 'http_only' => true, 'same_site' => 'lax'],
        'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
        'logging' => ['default' => 'single', 'channels' => ['single' => ['driver' => 'single', 'path' => $host.'/storage/logs/laravel.log']]],
        'concierge' => ['features' => ['membership' => false], 'local_login' => array_replace_recursive(['enabled' => true, 'account' => ['email' => 'cache@example.test', 'name' => 'Cache probe'], 'throttle' => 10], $localLogin)],
    ];
    foreach ($configs as $name => $config) {
        $files->put($host.'/config/'.$name.'.php', '<?php return '.var_export($config, true).';');
    }
    $database = new PDO('sqlite:'.$host.'/database.sqlite');
    $database->exec('CREATE TABLE plain_accounts (id INTEGER PRIMARY KEY, email VARCHAR UNIQUE, name VARCHAR, password VARCHAR, email_verified_at DATETIME, remember_token VARCHAR, created_at DATETIME, updated_at DATETIME)');
}

function cache_login_temporary_host(): string
{
    return sys_get_temp_dir().'/concierge-cache-'.bin2hex(random_bytes(12));
}

function cache_login_accounts(string $host): int
{
    return (int) (new PDO('sqlite:'.$host.'/database.sqlite'))->query('SELECT COUNT(*) FROM plain_accounts')->fetchColumn();
}

/**
 * @return array<string, mixed>
 */
function cache_login_json(Process $process): array
{
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * A port nothing listens on right now (the OS picks it).
 */
function cache_login_free_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    return $port;
}

/**
 * Wait until something accepts TCP connections on the port, or fail with the server's output.
 */
function cache_login_await_port(int $port, Process $server): void
{
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline) {
        if (! $server->isRunning()) {
            throw new RuntimeException('Server exited: '.$server->getOutput().$server->getErrorOutput());
        }
        set_error_handler(fn () => true);
        try {
            $socket = stream_socket_client('tcp://127.0.0.1:'.$port, timeout: 0.2);
        } finally {
            restore_error_handler();
        }
        if ($socket !== false) {
            fclose($socket);

            return;
        }
        usleep(50_000);
    }
    throw new RuntimeException('Server did not listen on '.$port.': '.$server->getOutput().$server->getErrorOutput());
}
