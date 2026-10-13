<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use Spatie\Permission\PermissionServiceProvider;
use Tey\Mod\ModServiceProvider;
use Workbench\App\Models\User;
use Workbench\App\Models\Workspace;

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

it('grants the first owner only across a local application and process boundary, with real caches', function (string $mode, bool $cached, string $buildEnvironment, ?string $environment) {
    $host = cache_login_temporary_host();
    $files = new Filesystem;

    try {
        cache_login_host($host);
        $concierge = require $host.'/config/concierge.php';
        $concierge['features']['membership'] = true;
        $concierge['mode'] = $mode;
        $concierge['models'] = ['user' => User::class, 'workspace' => $mode === 'workspaces' ? Workspace::class : null];
        $auth = require $host.'/config/auth.php';
        $auth['providers']['users']['model'] = User::class;
        $permission = require __DIR__.'/../../vendor/spatie/laravel-permission/config/permission.php';
        $permission['teams'] = $mode === 'workspaces';
        $permission['column_names']['team_foreign_key'] = 'workspace_id';

        foreach (['concierge' => $concierge, 'auth' => $auth, 'permission' => $permission] as $name => $config) {
            $files->put($host.'/config/'.$name.'.php', '<?php return '.var_export($config, true).';');
        }

        $files->put($host.'/bootstrap/providers.php', '<?php return '.var_export([ModServiceProvider::class, PermissionServiceProvider::class], true).';');
        $files->makeDirectory($host.'/database/migrations', 0755, true);
        $files->copy(__DIR__.'/../../workbench/database/migrations/0001_01_01_000000_create_host_tables.php', $host.'/database/migrations/0001_create_host_tables.php');
        $files->copy(__DIR__.'/../../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub', $host.'/database/migrations/0002_create_permission_tables.php');
        cache_login_artisan($host, ['migrate', '--force'], 'local')->mustRun();
        cache_login_artisan($host, ['concierge:sync-roles'], 'local')->mustRun();

        $database = new PDO('sqlite:'.$host.'/database.sqlite');
        if ($mode === 'workspaces') {
            $database->exec("INSERT INTO workspaces (name, created_at) VALUES ('Oldest', '2020-01-01')");
        }

        if ($cached) {
            cache_login_artisan($host, ['config:cache'], $buildEnvironment)->mustRun();
            cache_login_artisan($host, ['route:cache'], $buildEnvironment)->mustRun();
        }

        $session = cache_login_process($host, 'login', $environment)->mustRun()->getOutput();
        $result = cache_login_json(cache_login_process($host, 'probe', $environment, 'csrf', $session));
        $available = $environment === 'local' && (! $cached || $buildEnvironment === 'local');

        expect($result['status'])->toBe($available ? 302 : 404)
            ->and($result['guest'])->toBe(! $available)
            ->and($result['config_cached'])->toBe($cached)
            ->and((int) $database->query('SELECT COUNT(*) FROM model_has_roles')->fetchColumn())->toBe($available ? 1 : 0)
            ->and((int) $database->query('SELECT COUNT(*) FROM users')->fetchColumn())->toBe($available ? 1 : 0);

        $overlay = $mode === 'app' ? 'concierge_accounts' : 'concierge_memberships';
        expect((int) $database->query('SELECT COUNT(*) FROM '.$overlay)->fetchColumn())->toBe($available ? 1 : 0);
        if ($mode === 'workspaces') {
            expect((int) $database->query('SELECT COUNT(*) FROM concierge_ownerships')->fetchColumn())->toBe($available ? 1 : 0);
        }
    } finally {
        unset($database);
        $files->deleteDirectory($host);
    }
})->with(['app', 'workspaces'])->with([false, true])->with(['local', 'production'])->with(['local', 'production', null]);
