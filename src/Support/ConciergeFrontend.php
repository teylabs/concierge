<?php

namespace Tey\Concierge\Support;

use Tey\Mod\Facades\Mod;

/**
 * Declares concierge's frontend to mod: the package's Inertia pages under the
 * `concierge` namespace, and the wiring a host needs to import the Vue kit. Mod
 * wires an app from these declarations with `php artisan mod:install inertia`;
 * concierge never writes to the app itself.
 *
 * Paths are the ones Composer installs under vendor/, whether the package is
 * copied or symlinked from a path repository. Mod keeps them lexical and reports
 * any it cannot use (the package's own test skeleton has no vendor copy) as
 * frontend notices in `mod:list --json` instead of failing boot.
 */
final class ConciergeFrontend
{
    public const PAGES_NAMESPACE = 'concierge';

    public const ALIAS = '@tey/concierge';

    public const PACKAGE_PATH = 'vendor/tey/concierge';

    public static function register(string $basePath): void
    {
        Mod::pages(self::PAGES_NAMESPACE, $basePath.'/'.self::PACKAGE_PATH.'/resources/js/pages');
        Mod::wiring()
            ->alias(self::ALIAS, self::PACKAGE_PATH.'/resources/js')
            ->source(self::PACKAGE_PATH.'/resources/js');
    }
}
