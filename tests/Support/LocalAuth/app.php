<?php

use Illuminate\Foundation\Application;
use Tey\Concierge\ConciergeServiceProvider;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([ConciergeServiceProvider::class])
    ->withRouting(web: dirname(__DIR__).'/routes/web.php')
    ->withMiddleware()
    ->withExceptions()
    ->create();
