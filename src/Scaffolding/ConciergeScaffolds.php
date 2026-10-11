<?php

namespace Tey\Concierge\Scaffolding;

use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Scaffolds\Scaffold;

/**
 * The mod scaffold recipe concierge ships. A host app runs
 * `php artisan concierge:pages` to write the Inertia wrapper pages into its
 * own `resources/js/pages`, placed by the app's mod layout. Mod lists the same
 * recipe as mod:concierge/pages, the name it gives every package scaffold.
 *
 * Vue only for now: the stubs assume the Vue starter kit's `@/layouts/AppLayout.vue`.
 * Mod asks for a stub variant by the page extension it plans, so a React app is
 * told which `.tsx` stub to supply instead of receiving Vue code in a `.tsx` file.
 */
final class ConciergeScaffolds
{
    public const VENDOR = 'concierge';

    public const PAGES = 'pages';

    /** @var array<string, array{name: string, stub: string}> alias => page */
    public const PAGE_MEMBERS = [
        'members' => ['name' => '{name}/Members', 'stub' => 'concierge-members'],
        'inviteMember' => ['name' => '{name}/InviteMember', 'stub' => 'concierge-invite-member'],
        'member' => ['name' => '{name}/Member', 'stub' => 'concierge-member'],
        'acceptInvitation' => ['name' => '{name}/AcceptInvitation', 'stub' => 'concierge-accept-invitation'],
    ];

    /** The page extensions a stub variant can be shipped for: `stubs/mod/pages/<page>.<ext>.stub`. */
    public const EXTENSIONS = ['vue', 'tsx'];

    public static function register(): void
    {
        // Mod looks a planned `.vue` page up as "page.<variant>.vue", after the app's own
        // stubs/mod.page.<variant>.vue.stub. A variant registered under one extension is
        // never used for another, so each stack gets the stubs shipped for it and nothing else.
        foreach (self::PAGE_MEMBERS as ['stub' => $variant]) {
            foreach (self::EXTENSIONS as $extension) {
                $stub = self::stubPath($variant, $extension);
                if (is_file($stub)) {
                    Mod::stubs()->forFileType('page.'.$variant.'.'.$extension, Stub::file($stub));
                }
            }
        }

        Mod::vendor(self::VENDOR)->scaffold(self::PAGES, function (Scaffold $s): void {
            foreach (self::PAGE_MEMBERS as $alias => ['name' => $name, 'stub' => $stub]) {
                $s->makes('page', name: $name, as: $alias, stub: $stub);
            }
        });
    }

    /** The scaffold's full id, as mod's include, override and rename references take it. */
    public static function id(): string
    {
        return self::VENDOR.'/'.self::PAGES;
    }

    public static function stubPath(string $variant, string $extension = 'vue'): string
    {
        return dirname(__DIR__, 2).'/stubs/mod/pages/'.substr($variant, strlen('concierge-')).'.'.$extension.'.stub';
    }
}
