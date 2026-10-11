<?php

namespace Tey\Concierge\Scaffolding;

use Tey\Mod\Facades\Mod;
use Tey\Mod\Generation\Stub;
use Tey\Mod\Scaffolds\Scaffold;

/**
 * The mod scaffold recipes concierge ships. A host app runs
 * `php artisan concierge:pages` to write the Inertia wrapper pages into its
 * own `resources/js/pages`, placed by the app's mod layout. Mod also lists the
 * recipe as mod:concierge-pages, the name it gives every package scaffold.
 *
 * Spike (#17): Vue only. The stubs assume the Vue starter kit's `@/layouts/AppLayout.vue`.
 */
final class ConciergeScaffolds
{
    public const PAGES = 'concierge-pages';

    /** @var array<string, array{name: string, stub: string}> alias => page */
    public const PAGE_MEMBERS = [
        'members' => ['name' => '{name}/Members', 'stub' => 'concierge-members'],
        'inviteMember' => ['name' => '{name}/InviteMember', 'stub' => 'concierge-invite-member'],
        'member' => ['name' => '{name}/Member', 'stub' => 'concierge-member'],
        'acceptInvitation' => ['name' => '{name}/AcceptInvitation', 'stub' => 'concierge-accept-invitation'],
    ];

    public static function register(string $basePath): void
    {
        // A registered variant is looked up as "<file type>.<variant>", after the app's own
        // stubs/mod.page.<variant>.<ext>.stub. The key carries no extension, so the Vue stubs are
        // registered for a Vue app only; a React app is asked for the .tsx variant instead.
        if (self::inertiaAdapter($basePath) === 'vue') {
            foreach (self::PAGE_MEMBERS as ['stub' => $variant]) {
                Mod::stubs()->forFileType('page.'.$variant, Stub::file(self::stubPath($variant)));
            }
        }

        Mod::scaffold(self::PAGES, function (Scaffold $s): void {
            foreach (self::PAGE_MEMBERS as $alias => ['name' => $name, 'stub' => $stub]) {
                $s->makes('page', name: $name, as: $alias, stub: $stub);
            }
        });
    }

    /** The same reading mod makes of package.json: `vue`, `react` or null. */
    private static function inertiaAdapter(string $basePath): ?string
    {
        $file = $basePath.'/package.json';
        $package = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $dependencies = is_array($package) ? [...($package['dependencies'] ?? []), ...($package['devDependencies'] ?? [])] : [];

        return match (true) {
            isset($dependencies['@inertiajs/vue3']) => 'vue',
            isset($dependencies['@inertiajs/react']) => 'react',
            default => null,
        };
    }

    public static function stubPath(string $variant): string
    {
        return dirname(__DIR__, 2).'/stubs/mod/pages/'.substr($variant, strlen('concierge-')).'.vue.stub';
    }
}
