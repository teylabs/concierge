<?php

namespace Tey\Concierge\Tests\Scaffolding;

use Illuminate\Support\Facades\File;

/** A host on mod's modules layout: the pages go inside one of the app's modules. */
class ModulesScaffoldTestCase extends ScaffoldTestCase
{
    public const PAGES = 'app/Modules/Billing/resources/js/pages/Concierge';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mod.layout', 'modules');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('app/Modules'));

        parent::tearDown();
    }
}
