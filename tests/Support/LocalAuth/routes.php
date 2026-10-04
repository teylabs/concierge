<?php

use Illuminate\Support\Facades\Route;
use Tey\Concierge\Tests\Support\LocalAuth\LoginController;

Route::get('/login', LoginController::class)->name('login');
