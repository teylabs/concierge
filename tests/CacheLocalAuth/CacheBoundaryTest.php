<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;

require_once __DIR__.'/../Support/LocalAuth/host.php';

it('resolves every fixture cache override inside its host on each path style', function (string $host) {
    $app = new Application($host);
    $environment = cache_login_process($host, 'config:cache', 'local')->getEnv();
    $repository = Env::getRepository();

    foreach ([
        'APP_CONFIG_CACHE' => ['getCachedConfigPath', 'config.php'],
        'APP_ROUTES_CACHE' => ['getCachedRoutesPath', 'routes.php'],
        'APP_SERVICES_CACHE' => ['getCachedServicesPath', 'services.php'],
        'APP_PACKAGES_CACHE' => ['getCachedPackagesPath', 'packages.php'],
        'APP_EVENTS_CACHE' => ['getCachedEventsPath', 'events.php'],
    ] as $key => [$method, $file]) {
        $original = $repository->get($key);
        try {
            $repository->set($key, $environment[$key]);
            expect(str_replace('\\', '/', $app->$method()))
                ->toBe(str_replace('\\', '/', $host).'/bootstrap/cache/'.$file);
        } finally {
            if ($original === null) {
                $repository->clear($key);
            } else {
                $repository->set($key, $original);
            }
        }
    }
})->with([
    'Windows drive with backslashes' => ['C:\\Users\\runner\\AppData\\Local\\Temp/concierge-cache-probe'],
    'Windows drive with forward slashes' => ['C:/Users/runner/AppData/Local/Temp/concierge-cache-probe'],
    'Windows UNC' => ['\\\\server\\share\\Temp/concierge-cache-probe'],
    'POSIX' => ['/tmp/concierge-cache-probe'],
]);

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
