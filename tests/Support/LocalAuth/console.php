<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tey\Concierge\LocalAuth\LocalLogin;

// Fixture only: what a command (or a queued job, which boots the same console kernel) sees.
Artisan::command('local-login:probe', function (LocalLogin $localLogin) {
    $this->line(json_encode([
        'application_environment' => app()->environment(),
        'route' => Route::has(LocalLogin::ROUTE),
        'available' => $localLogin->isAvailable(),
        'offer' => $localLogin->offer() !== null,
    ], JSON_THROW_ON_ERROR));
});
