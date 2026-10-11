<?php

namespace Tey\Concierge\Support;

use Tey\Mod\Facades\Mod;

/**
 * Declares concierge's frontend to mod: the package's Inertia pages under the
 * `concierge` namespace, and the wiring a host needs to import the Vue kit. Mod
 * wires an app from these declarations with `php artisan mod:install inertia`;
 * concierge never writes to the app itself.
 *
 * Declared only when the package sits inside the app, where Composer installs
 * it (vendor/tey/concierge). A path repository symlinked from elsewhere, and the
 * package's own test skeleton, have no in-app copy for mod to wire or Vite to glob.
 */
final class ConciergeFrontend
{
    public const PAGES_NAMESPACE = 'concierge';

    public const ALIAS = '@tey/concierge';

    /** @param  string|null  $package  the package root; the installed copy of this package by default */
    public static function register(string $basePath, ?string $package = null): void
    {
        $package ??= dirname(__DIR__, 2);
        $relative = self::installedInside($basePath, $package);

        if ($relative === null) {
            return;
        }

        Mod::pages(self::PAGES_NAMESPACE, $basePath.'/'.$relative.'/resources/js/pages');
        Mod::wiring()
            ->alias(self::ALIAS, $relative.'/resources/js')
            ->source($relative.'/resources/js');
    }

    /**
     * The package root relative to the app, or null when it is not a real
     * directory inside the app (absent, outside it, or a symlink leading out).
     */
    public static function installedInside(string $basePath, string $package): ?string
    {
        $base = realpath($basePath);
        $real = realpath($package);
        if ($base === false || $real === false || ! is_dir($real.'/resources/js/pages')) {
            return null;
        }
        $prefix = rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($real, $prefix) ? str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($prefix))) : null;
    }
}
