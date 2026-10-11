<?php

// Each invocation is a fresh Laravel boot; all writable paths belong to the test host.
//
// argv: host, command, csrf, session JSON, options JSON. Options:
// - `server` / `env`: put APP_ENV only in $_SERVER or only in $_ENV before booting, the way a
//   SAPI hands it over without a process environment variable.
// - `putenv`: change (string) or remove (null) the process APP_ENV after booting, before probing.
// - `steps` (command `worker`): one boot serving several GET-token-POST rounds, each after an
//   optional `putenv`, resetting per-request state the way Octane does between requests.
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Tey\Concierge\LocalAuth\LocalLogin;
use Workbench\App\Models\LocalAuth\PlainAccount;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$options = json_decode($argv[5] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
foreach (['server' => '_SERVER', 'env' => '_ENV'] as $option => $global) {
    if (array_key_exists($option, $options)) {
        $GLOBALS[$global]['APP_ENV'] = $options[$option];
    }
}

$app = require $argv[1].'/bootstrap/app.php';

if (! in_array($argv[2], ['probe', 'login', 'worker'], true)) {
    $kernel = $app->make(ConsoleKernel::class);
    exit($kernel->call($argv[2]));
}

$kernel = $app->make(HttpKernel::class);
$app->instance('request', Request::create('http://localhost/local-login', 'POST'));
$kernel->bootstrap();

if (array_key_exists('putenv', $options)) {
    putenv($options['putenv'] === null ? 'APP_ENV' : 'APP_ENV='.$options['putenv']);
}

/**
 * @param  array<string, string>  $cookies
 * @return array{status: int, body: mixed, cookies: array<string, string>}
 */
$send = function (Request $request) use ($kernel): array {
    $response = $kernel->handle($request);
    $cookies = [];
    foreach ($response->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = $cookie->getValue();
    }
    $kernel->terminate($request, $response);

    return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true), 'cookies' => $cookies];
};

$session = json_decode($argv[4], true, flags: JSON_THROW_ON_ERROR);
$cookies = $session['cookies'] ?? [];

if ($argv[2] === 'login') {
    echo json_encode($send(Request::create('http://localhost/login', 'GET', [], $cookies)), JSON_THROW_ON_ERROR);
    exit(0);
}

if ($argv[2] === 'worker') {
    $results = [];
    foreach ($options['steps'] as $step) {
        if (array_key_exists('putenv', $step)) {
            putenv($step['putenv'] === null ? 'APP_ENV' : 'APP_ENV='.$step['putenv']);
        }
        // What Octane resets between requests: guards and the session store.
        $app->make('auth')->forgetGuards();
        $app->make('session')->forgetDrivers();
        $login = $send(Request::create('http://localhost/login'));
        $app->make('auth')->forgetGuards();
        $app->make('session')->forgetDrivers();
        $post = $send(Request::create('http://localhost/local-login', 'POST', ['_token' => $login['body']['token']], $login['cookies']));
        $results[] = ['offer' => $login['body']['offer'], 'status' => $post['status']];
    }
    echo json_encode(['results' => $results, 'accounts' => PlainAccount::query()->count()], JSON_THROW_ON_ERROR);
    exit(0);
}

$login = $app->make(LocalLogin::class);
$offer = $login->offer();
$input = isset($session['body']['token']) ? ['_token' => $session['body']['token']] : [];
$router = $app->make('router');
$route = $router->getRoutes()->getByName(LocalLogin::ROUTE);

$response = $send(Request::create('http://localhost/local-login', 'POST', $input, $cookies));
echo json_encode([
    'application_environment' => $app->environment(),
    'configured_environment' => config('app.env'),
    'config_cached' => $app->configurationIsCached(),
    'routes_cached' => $app->routesAreCached(),
    'route' => $router->has(LocalLogin::ROUTE),
    'middleware' => $route === null ? null : array_values(array_filter($router->gatherRouteMiddleware($route), is_string(...))),
    'offer' => $offer !== null,
    'status' => $response['status'],
    'cookies' => $response['cookies'],
    'guest' => $app->make('auth')->guard()->guest(),
    'accounts' => PlainAccount::query()->count(),
], JSON_THROW_ON_ERROR);
