<?php

// Each invocation is a fresh Laravel boot; all writable paths belong to the test host.
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Tey\Concierge\LocalAuth\LocalLogin;
use Workbench\App\Models\LocalAuth\PlainAccount;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = require $argv[1].'/bootstrap/app.php';

if (! in_array($argv[2], ['probe', 'login'], true)) {
    $kernel = $app->make(ConsoleKernel::class);
    exit($kernel->call($argv[2]));
}

$kernel = $app->make(HttpKernel::class);
$app->instance('request', Request::create('http://localhost/local-login', 'POST'));
$kernel->bootstrap();
if ($argv[2] === 'login') {
    $request = Request::create('http://localhost/login');
    $response = $kernel->handle($request);
    $cookies = [];
    foreach ($response->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = $cookie->getValue();
    }
    echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR), 'cookies' => $cookies], JSON_THROW_ON_ERROR);
    $kernel->terminate($request, $response);
    exit(0);
}

$login = $app->make(LocalLogin::class);
$offer = $login->offer();
$session = json_decode($argv[4], true, flags: JSON_THROW_ON_ERROR);
$cookies = $session['cookies'] ?? [];
$input = isset($session['body']['token']) ? ['_token' => $session['body']['token']] : [];

$request = Request::create('http://localhost/local-login', 'POST', $input, $cookies);
$response = $kernel->handle($request);
$result = [
    'application_environment' => $app->environment(),
    'configured_environment' => config('app.env'),
    'config_cached' => $app->configurationIsCached(),
    'routes_cached' => $app->routesAreCached(),
    'route' => $app->make('router')->has(LocalLogin::ROUTE),
    'offer' => $offer !== null,
    'status' => $response->getStatusCode(),
    'guest' => $app->make('auth')->guard()->guest(),
    'accounts' => PlainAccount::query()->count(),
];
$kernel->terminate($request, $response);
echo json_encode($result, JSON_THROW_ON_ERROR);
