<?php

namespace Tey\Concierge\Tests\Fortify;

use Laravel\Fortify\Features;
use Laravel\Fortify\FortifyServiceProvider;
use Spatie\Permission\PermissionServiceProvider;
use Tey\Concierge\ConciergeServiceProvider;
use Tey\Concierge\Tests\TestCase;
use Tey\Mod\ModServiceProvider;
use Workbench\App\Providers\FortifyServiceProvider as HostFortifyServiceProvider;

/**
 * A host on a Fortify starter kit: Fortify's routes, the kit's CreateNewUser
 * and register view, and concierge installed with no registration code of
 * its own. The host's provider boots after concierge's, as in a real app.
 */
class FortifyTestCase extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            ModServiceProvider::class,
            PermissionServiceProvider::class,
            FortifyServiceProvider::class,
            ConciergeServiceProvider::class,
            HostFortifyServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);

        $app['config']->set('fortify.guard', 'web');
        $app['config']->set('fortify.home', '/home');
        $app['config']->set('fortify.views', true);
        $app['config']->set('fortify.features', [Features::registration()]);
    }
}
