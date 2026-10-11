<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Support/LocalAuth/host.php';
require_once __DIR__.'/../Support/LocalAuth/http.php';

/**
 * Issue #9, the servers: real php-fpm and real `php artisan serve`, each driven over the wire
 * through the GET-session-CSRF-POST sign-in. Octane is not booted here (no Swoole, RoadRunner or
 * FrankenPHP in this toolchain); the long-lived worker case is in EnvironmentMatrixTest.
 */

/**
 * A php-fpm of the same PHP version as the test runner, or null. LOCAL_LOGIN_PHP_FPM overrides.
 */
function local_login_php_fpm(): ?string
{
    $candidates = array_filter([getenv('LOCAL_LOGIN_PHP_FPM') ?: null, PHP_BINARY.'-fpm', (new ExecutableFinder)->find('php-fpm')]);
    foreach ($candidates as $candidate) {
        $version = new Process([$candidate, '-v']);
        if (is_executable($candidate) && $version->run() === 0 && str_starts_with($version->getOutput(), 'PHP '.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.')) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Start php-fpm for the host with one worker. `$pool` is the pool's env[] block; clear_env is on,
 * as in a default pool, so nothing leaks in from this test process.
 *
 * @param  array<string, string>  $pool
 */
function local_login_start_fpm(string $binary, string $host, array $pool): array
{
    $port = cache_login_free_port();
    $env = '';
    foreach ([...cache_login_cache_paths(), ...$pool] as $name => $value) {
        $env .= "env[{$name}] = \"{$value}\"\n";
    }
    file_put_contents($host.'/fpm.conf', "[global]\nerror_log = {$host}/storage/logs/fpm.log\ndaemonize = no\n\n[www]\nlisten = 127.0.0.1:{$port}\npm = static\npm.max_children = 1\nclear_env = yes\ncatch_workers_output = yes\n{$env}");
    $fpm = new Process([$binary, '-F', '-y', $host.'/fpm.conf', '-p', $host], $host, ['APP_ENV' => false]);
    $fpm->start();
    cache_login_await_port($port, $fpm);

    return [$fpm, $port];
}

it('fails closed under php-fpm unless the request process sees local', function (array $pool, array $params, bool $cached, bool $open) {
    $binary = local_login_php_fpm() ?? test()->markTestSkipped('No php-fpm for PHP '.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'; set LOCAL_LOGIN_PHP_FPM.');
    $host = cache_login_temporary_host();
    $fpm = null;
    try {
        cache_login_host($host);
        if ($cached) {
            cache_login_process($host, 'config:cache', 'local')->mustRun();
        } else {
            file_put_contents($host.'/.env', "APP_ENV=local\n");
        }
        [$fpm, $port] = local_login_start_fpm($binary, $host, $pool);
        $script = $host.'/public/index.php';

        $login = local_login_fastcgi($port, $script, 'GET', '/login', params: $params);
        expect($login['status'])->toBe(200, json_encode($login))
            ->and($login['body'])->toMatchArray(['config_cached' => $cached, 'offer' => $open]);
        $post = local_login_fastcgi($port, $script, 'POST', '/local-login', $login['cookies'], ['_token' => $login['body']['token']], $params);

        expect($post['status'])->toBe($open ? 302 : 404)
            ->and(cache_login_accounts($host))->toBe($open ? 1 : 0);
    } finally {
        $fpm?->stop(5);
        (new Filesystem)->deleteDirectory($host);
    }
})->with([
    'config cached local, pool env local' => [['APP_ENV' => 'local'], [], true, true],
    'config cached local, pool env production' => [['APP_ENV' => 'production'], [], true, false],
    'config cached local, no APP_ENV anywhere' => [[], [], true, false],
    'config cached local, fastcgi_param local' => [[], ['APP_ENV' => 'local'], true, true],
    'config cached local, fastcgi_param production over pool local' => [['APP_ENV' => 'local'], ['APP_ENV' => 'production'], true, false],
    'config cached local, request header APP-ENV: local' => [[], ['HTTP_APP_ENV' => 'local'], true, false],
    'uncached, .env local' => [[], [], false, true],
    'uncached, .env local, pool env production' => [['APP_ENV' => 'production'], [], false, false],
]);

it('fails closed under php artisan serve unless its process sees local', function (?string $process, bool $cached, bool $open) {
    $host = cache_login_temporary_host();
    $server = null;
    try {
        cache_login_host($host);
        file_put_contents($host.'/.env', "APP_ENV=local\n");
        if ($cached) {
            cache_login_process($host, 'config:cache', 'local')->mustRun();
        }
        $port = cache_login_free_port();
        $server = cache_login_artisan($host, ['serve', '--host=127.0.0.1', '--port='.$port], $process);
        $server->start();
        cache_login_await_port($port, $server);

        $login = local_login_http($port, 'GET', '/login');
        expect($login['status'])->toBe(200, json_encode($login))
            ->and($login['body'])->toMatchArray(['config_cached' => $cached, 'offer' => $open]);
        $post = local_login_http($port, 'POST', '/local-login', $login['cookies'], ['_token' => $login['body']['token']]);

        expect($post['status'])->toBe($open ? 302 : 404)
            ->and(cache_login_accounts($host))->toBe($open ? 1 : 0);
    } finally {
        $server?->stop(5);
        (new Filesystem)->deleteDirectory($host);
    }
})->with([
    // Uncached, serve passes the .env value through to the PHP server it starts.
    'uncached, .env local, no process value' => [null, false, true],
    'uncached, .env local, process production' => ['production', false, false],
    // Cached, .env is never read: a cached local development boot must set APP_ENV itself.
    'config cached local, .env local, no process value' => [null, true, false],
    'config cached local, process local' => ['local', true, true],
    'config cached local, process production' => ['production', true, false],
]);
