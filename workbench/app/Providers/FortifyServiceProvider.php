<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;
use Workbench\App\Actions\Fortify\CreateNewUser;

/**
 * The app's Fortify provider as a starter kit generates it, reduced to
 * registration. It binds the user creator and the register view; concierge's
 * provider boots before it, as a package provider would in a real app.
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::registerView(fn () => response('register form'));
    }
}
