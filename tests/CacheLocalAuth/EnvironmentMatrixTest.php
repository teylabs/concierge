<?php

use Illuminate\Filesystem\Filesystem;
use Tey\Concierge\LocalAuth\Middleware\EnsureLocalLoginAvailable;

require_once __DIR__.'/../Support/LocalAuth/host.php';

/**
 * Issue #9: local login is offered and accepted only when Laravel's environment AND the raw
 * process APP_ENV are exactly `local`. Every cell boots a real throwaway host in fresh PHP
 * processes (real cache files, real .env, real process environment) and attempts the real
 * GET-session-CSRF-POST sign-in.
 */
const LOCAL_LOGIN_VALUES = [
    'local' => 'local',
    'Local' => 'Local',
    'padded' => ' local ',
    'production' => 'production',
    'staging' => 'staging',
    'testing' => 'testing',
    'empty' => '',
    'missing' => null,
];

function local_login_matrix_values(): array
{
    return array_map(fn (?string $value) => [$value], LOCAL_LOGIN_VALUES);
}

/**
 * Run `$test` against a fresh host and always remove it.
 */
function local_login_with_host(Closure $test, array $localLogin = []): void
{
    $host = cache_login_temporary_host();
    try {
        cache_login_host($host, $localLogin);
        $test($host);
    } finally {
        (new Filesystem)->deleteDirectory($host);
    }
}

/**
 * The real attack: fetch a session and token, then POST with both.
 *
 * @param  array<string, mixed>  $options
 * @return array<string, mixed>
 */
function local_login_attempt(string $host, ?string $environment, array $options = []): array
{
    $session = cache_login_process($host, 'login', $environment, options: $options)->mustRun()->getOutput();
    expect(json_decode($session, true)['status'])->toBe(200);

    return cache_login_json(cache_login_process($host, 'probe', $environment, 'csrf', $session, $options));
}

function local_login_expect(array $result, bool $route, bool $open): void
{
    expect($result)->toMatchArray([
        'route' => $route,
        'offer' => $open,
        'status' => $open ? 302 : 404,
        'guest' => ! $open,
        'accounts' => $open ? 1 : 0,
    ]);
}

it('opens only for an exact local value, from each place APP_ENV can come from', function (string $source, ?string $value) {
    local_login_with_host(function (string $host) use ($source, $value) {
        $process = $source === 'process' ? $value : null;
        $options = [];
        if ($value !== null && in_array($source, ['$_SERVER only', '$_ENV only'], true)) {
            $options[$source === '$_SERVER only' ? 'server' : 'env'] = $value;
        }
        if ($source === '.env') {
            file_put_contents($host.'/.env', $value === null ? "APP_DEBUG=false\n" : 'APP_ENV="'.$value."\"\n");
        }

        $result = local_login_attempt($host, $process, $options);

        // $_ENV alone is never read by the raw check, so it can only ever fail closed.
        $open = $value === 'local' && $source !== '$_ENV only';
        expect($result['config_cached'])->toBeFalse();
        local_login_expect($result, route: $open, open: $open);
    });
})->with(['.env', 'process', '$_SERVER only', '$_ENV only'])->with(local_login_matrix_values());

it('rechecks a putenv made at runtime, after the application booted', function (string $booted, ?string $value) {
    local_login_with_host(function (string $host) use ($booted, $value) {
        $result = local_login_attempt($host, $booted, ['putenv' => $value]);

        // Removing the process value falls back to $_SERVER, which still holds the boot value.
        // Windows can't hold an empty variable: putenv('APP_ENV=') removes it, so it falls back too.
        $removed = $value === null || ($value === '' && PHP_OS_FAMILY === 'Windows');
        $raw = $removed ? $booted : $value;
        local_login_expect($result, route: $booted === 'local', open: $booted === 'local' && $raw === 'local');
    });
})->with(['local', 'production'])->with(local_login_matrix_values());

it('opens only for an exact local value under every combination of caches', function (array $builds, ?string $value) {
    local_login_with_host(function (string $host) use ($builds, $value) {
        foreach ($builds as [$command, $environment]) {
            cache_login_process($host, $command, $environment)->mustRun();
        }
        $built = array_column($builds, 1, 0);
        $configBuild = $built['config:cache'] ?? (isset($built['optimize']) ? 'local' : null);
        $routeBuild = $built['route:cache'] ?? (isset($built['optimize']) ? 'local' : null);
        expect(is_file($host.'/bootstrap/cache/config.php'))->toBe($configBuild !== null)
            ->and(is_file($host.'/bootstrap/cache/routes.php'))->toBe($routeBuild !== null)
            ->and(is_file($host.'/bootstrap/cache/events.php'))->toBe(isset($built['event:cache']) || isset($built['optimize']));

        $result = local_login_attempt($host, $value);

        $application = $configBuild ?? $value;
        $available = $application === 'local' && $value === 'local';
        $route = $routeBuild === null ? $available : $routeBuild === 'local';
        expect($result['application_environment'])->toBe($application ?? 'production');
        local_login_expect($result, route: $route, open: $available && $route);
    });
})->with([
    'no caches' => [[]],
    'config' => [[['config:cache', 'local']]],
    'routes' => [[['route:cache', 'local']]],
    'events' => [[['event:cache', 'local']]],
    'optimize (all of them)' => [[['optimize', 'local']]],
    'config local, routes production' => [[['route:cache', 'production'], ['config:cache', 'local']]],
    'config production, routes local' => [[['route:cache', 'local'], ['config:cache', 'production']]],
])->with(local_login_matrix_values());

it('denies a locally cached route before CSRF, the guest check and the throttle', function () {
    local_login_with_host(function (string $host) {
        cache_login_process($host, 'optimize', 'local')->mustRun();

        // The middleware Laravel actually runs, after its priority sort: ours comes first.
        $denied = cache_login_json(cache_login_process($host, 'probe', 'production'));
        expect($denied['middleware'][0])->toBe(EnsureLocalLoginAvailable::class)
            ->and($denied['status'])->toBe(404);

        // No token: local answers 419 from CSRF, production 404 before it.
        expect(cache_login_json(cache_login_process($host, 'probe', 'local'))['status'])->toBe(419);

        // Signed in (the session is valid in production too): still 404, where the guest check
        // would redirect. The denial comes before the session even starts.
        $signedIn = local_login_attempt($host, 'local');
        expect($signedIn['status'])->toBe(302)->and($signedIn['guest'])->toBeFalse();
        $session = cache_login_process($host, 'login', 'production', session: json_encode(['cookies' => $signedIn['cookies']]))->mustRun()->getOutput();
        expect(json_decode($session, true)['body']['guest'])->toBeFalse();
        expect(cache_login_json(cache_login_process($host, 'probe', 'production', 'csrf', $session)))
            ->toMatchArray(['status' => 404, 'cookies' => [], 'accounts' => 1]);
    });
});

it('denies before the throttle: a production worker never answers 429', function () {
    local_login_with_host(function (string $host) {
        cache_login_process($host, 'optimize', 'local')->mustRun();
        $steps = ['steps' => [[], [], []]];

        expect(cache_login_json(cache_login_process($host, 'worker', 'local', options: $steps))['results'])
            ->toBe([['offer' => true, 'status' => 302], ['offer' => true, 'status' => 429], ['offer' => true, 'status' => 429]]);
        expect(cache_login_json(cache_login_process($host, 'worker', 'production', options: $steps)))
            ->toBe(['results' => array_fill(0, 3, ['offer' => false, 'status' => 404]), 'accounts' => 1]);
    }, ['throttle' => 1]);
});

it('rechecks every request in a long-lived worker (the Octane case)', function (bool $cached) {
    local_login_with_host(function (string $host) use ($cached) {
        if ($cached) {
            cache_login_process($host, 'optimize', 'local')->mustRun();
        }
        $steps = array_map(fn (?string $value) => ['putenv' => $value], ['local', 'production', '', 'Local', ' local ', 'local', null]);

        // Booted local: open, then shut the moment the process value changes, and open again.
        // Removing it falls back to $_SERVER, which holds the value the worker started with.
        // Windows can't hold an empty variable, so the empty step is a removal there too.
        $empty = PHP_OS_FAMILY === 'Windows';
        $local = cache_login_json(cache_login_process($host, 'worker', 'local', options: ['steps' => $steps]));
        expect(array_column($local['results'], 'status'))->toBe([302, 404, $empty ? 302 : 404, 404, 404, 302, 302])
            ->and(array_column($local['results'], 'offer'))->toBe([true, false, $empty, false, false, true, true]);

        // Booted production: uncached, Laravel's environment stays production and nothing opens.
        // Optimized locally, Laravel's is `local` from the cache, so only the steps where the
        // process value is also `local` open; removing it falls back to production.
        $production = cache_login_json(cache_login_process($host, 'worker', 'production', options: ['steps' => $steps]));
        expect(array_column($production['results'], 'status'))->toBe($cached ? [302, 404, 404, 404, 404, 302, 404] : array_fill(0, 7, 404))
            ->and($production['accounts'])->toBe(1);
    });
})->with(['uncached' => false, 'optimized' => true]);

it('never offers it to a command unless both environments are local', function (array $arguments, ?string $process, ?string $dotenvLocal, bool $available) {
    local_login_with_host(function (string $host) use ($arguments, $process, $dotenvLocal, $available) {
        if ($dotenvLocal !== null) {
            file_put_contents($host.'/.env.local', "APP_ENV={$dotenvLocal}\n");
        }

        $probe = cache_login_artisan($host, [...$arguments, 'local-login:probe'], $process)->mustRun();

        expect(json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR))
            ->toMatchArray(['route' => $available, 'available' => $available, 'offer' => $available]);
    });
})->with([
    'no --env, process local' => [[], 'local', null, true],
    'no --env, process production' => [[], 'production', null, false],
    '--env=local, process local' => [['--env=local'], 'local', null, true],
    '--env=local, process production' => [['--env=local'], 'production', null, false],
    '--env=local, process missing' => [['--env=local'], null, null, false],
    '--env=local, process missing, .env.local says local' => [['--env=local'], null, 'local', true],
    '--env=local, process production, .env.local says local' => [['--env=local'], 'production', 'local', false],
    '--env=production, process local' => [['--env=production'], 'local', null, false],
]);

it('refuses a route cached by a --env=local command once served without a local process', function (?string $served) {
    local_login_with_host(function (string $host) use ($served) {
        file_put_contents($host.'/.env.local', "APP_ENV=local\n");
        cache_login_artisan($host, ['--env=local', 'optimize'], null)->mustRun();

        $result = local_login_attempt($host, $served);
        expect($result['application_environment'])->toBe('local');
        local_login_expect($result, route: true, open: false);
    });
})->with(['production', 'staging', 'missing' => null]);
