<?php

namespace Tey\Concierge\Tests\Setup\Fixtures;

use Illuminate\Support\ServiceProvider;

/**
 * A package that supplies concierge's config from its own register(), after
 * concierge's provider has registered: the way the kit merges its defaults
 * when the app has no config/concierge.php.
 */
class LateSetupProvider extends ServiceProvider
{
    public function register(): void
    {
        config()->set('concierge.setup', true);
    }
}
