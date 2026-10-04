<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Workbench\App\Models\LocalAuth\PlainAccount;

function cache_login_process(string $host, string $command, ?string $environment, string $csrf = 'none', string $session = '{}'): Process
{
    // false removes inherited APP_ENV even on Windows; no host .env is created.
    return new Process([PHP_BINARY, __DIR__.'/../Support/LocalAuth/boot.php', $host, $command, $csrf, $session], null, [
        'APP_ENV' => $environment ?? false,
        'APP_CONFIG_CACHE' => $host.'/bootstrap/cache/config.php',
        'APP_ROUTES_CACHE' => $host.'/bootstrap/cache/routes.php',
        'APP_SERVICES_CACHE' => $host.'/bootstrap/cache/services.php',
        'APP_PACKAGES_CACHE' => $host.'/bootstrap/cache/packages.php',
        'APP_EVENTS_CACHE' => $host.'/bootstrap/cache/events.php',
    ]);
}

function cache_login_host(string $host): void
{
    $files = new Filesystem;
    foreach (['bootstrap/cache', 'config', 'routes', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $path) {
        $files->makeDirectory($host.'/'.$path, 0755, true);
    }
    $files->copy(__DIR__.'/../Support/LocalAuth/app.php', $host.'/bootstrap/app.php');
    $files->put($host.'/composer.json', '{"autoload":{"psr-4":{"App\\\\":"app/"}}}');
    $files->put($host.'/bootstrap/providers.php', '<?php return [];');
    $files->copy(__DIR__.'/../Support/LocalAuth/routes.php', $host.'/routes/web.php');
    $files->put($host.'/config/app.php', "<?php return ['env' => env('APP_ENV', 'production'), 'key' => 'base64:".base64_encode(str_repeat('k', 32))."', 'cipher' => 'AES-256-CBC', 'url' => 'http://localhost'];");
    $configs = [
        'auth' => ['defaults' => ['guard' => 'web', 'passwords' => 'users'], 'guards' => ['web' => ['driver' => 'session', 'provider' => 'users']], 'providers' => ['users' => ['driver' => 'eloquent', 'model' => PlainAccount::class]]],
        'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => $host.'/database.sqlite', 'prefix' => '']]],
        'session' => ['driver' => 'file', 'files' => $host.'/storage/framework/sessions', 'cookie' => 'cache_probe_session', 'lifetime' => 120, 'lottery' => [0, 100], 'path' => '/', 'http_only' => true, 'same_site' => 'lax'],
        'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
        'logging' => ['default' => 'single', 'channels' => ['single' => ['driver' => 'single', 'path' => $host.'/storage/logs/laravel.log']]],
        'concierge' => ['features' => ['membership' => false], 'local_login' => ['enabled' => true, 'account' => ['email' => 'cache@example.test', 'name' => 'Cache probe'], 'throttle' => 10]],
    ];
    foreach ($configs as $name => $config) {
        $files->put($host.'/config/'.$name.'.php', '<?php return '.var_export($config, true).';');
    }
    $database = new PDO('sqlite:'.$host.'/database.sqlite');
    $database->exec('CREATE TABLE plain_accounts (id INTEGER PRIMARY KEY, email VARCHAR UNIQUE, name VARCHAR, password VARCHAR, email_verified_at DATETIME, remember_token VARCHAR, created_at DATETIME, updated_at DATETIME)');
}

it('enforces the process boundary after a real local cache build', function (bool $routeCache, ?string $environment, string $csrf) {
    $host = sys_get_temp_dir().'/concierge-cache-'.bin2hex(random_bytes(12));
    $files = new Filesystem;
    try {
        cache_login_host($host);
        cache_login_process($host, 'config:cache', 'local')->mustRun();
        expect(is_file($host.'/bootstrap/cache/config.php'))->toBeTrue();
        if ($routeCache) {
            cache_login_process($host, 'route:cache', 'local')->mustRun();
            expect(is_file($host.'/bootstrap/cache/routes.php'))->toBeTrue();
        }
        $session = '{}';
        if ($csrf === 'csrf') {
            $session = cache_login_process($host, 'login', $environment)->mustRun()->getOutput();
            $login = json_decode($session, true, flags: JSON_THROW_ON_ERROR);
            expect($login['status'])->toBe(200)
                ->and($login['body']['token'])->toBeString()->not->toBeEmpty()
                ->and($login['cookies']['cache_probe_session'])->toBeString()->not->toBeEmpty();
        }
        $probe = cache_login_process($host, 'probe', $environment, $csrf, $session)->mustRun();
        expect($probe->getOutput())->toStartWith('{');
        $result = json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($result)->toMatchArray([
            'application_environment' => 'local',
            'configured_environment' => 'local',
            'config_cached' => true,
            'routes_cached' => $routeCache,
            'route' => $routeCache || $environment === 'local',
            'offer' => $environment === 'local',
            'status' => $environment === 'local' ? ($csrf === 'csrf' ? 302 : 419) : 404,
            'guest' => $environment !== 'local' || $csrf !== 'csrf',
            'accounts' => $environment === 'local' && $csrf === 'csrf' ? 1 : 0,
        ]);
    } finally {
        $files->deleteDirectory($host);
        expect(is_dir($host))->toBeFalse();
    }
})->with([false, true])->with(['local', 'production', 'staging', 'testing', null])->with(['none', 'csrf']);
