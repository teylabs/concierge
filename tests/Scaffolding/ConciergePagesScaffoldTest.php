<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tey\Concierge\Scaffolding\ConciergeScaffolds;
use Tey\Concierge\Support\ConciergeFrontend;
use Tey\Concierge\Tests\Scaffolding\ScaffoldTestCase;
use Tey\Mod\Facades\Mod;

function run_scaffold(array $options = []): array
{
    $output = new BufferedOutput;
    $exit = app(Kernel::class)->call('concierge:pages', ['--no-interaction' => true] + $options, $output);

    return [$exit, $output->fetch()];
}

it('is listed by mod as a scaffold shipped by the package', function () {
    [$exit, $output] = run_scaffold();
    $output = new BufferedOutput;
    app(Kernel::class)->call('mod:list', ['--json' => true], $output);
    $list = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

    expect($list['layout'])->toBe('laravel')
        ->and($list['stack'])->toBe(['inertia' => 'vue', 'typescript' => true, 'pages' => 'resources/js/pages', 'livewire' => false])
        ->and(collect($list['scaffolds']['items'])->firstWhere('id', ConciergeScaffolds::id()))
        ->toMatchArray(['vendor' => 'concierge', 'name' => 'pages', 'command' => 'mod:concierge/pages', 'package' => 'tey/concierge', 'notices' => []])
        ->and($list['scaffolds']['problems'])->toBe([]);
});

function mod_list(): array
{
    $output = new BufferedOutput;
    app(Kernel::class)->call('mod:list', ['--json' => true], $output);

    return json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * Composer installs the package at vendor/tey/concierge. The skeleton has no copy (and its
 * vendor/ is Testbench's link to the real one), so these tests put one there: a symlink to
 * this checkout, as a path repository installs it, or a copy of the pages.
 */
/** Link this checkout at $path as a path repository does: a symlink, or a junction on Windows. */
function link_package(string $path): bool
{
    if (@symlink(dirname(__DIR__, 2), $path)) {
        return true;
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        return false;
    }
    exec('mklink /J '.escapeshellarg(str_replace('/', '\\', $path)).' '.escapeshellarg(str_replace('/', '\\', dirname(__DIR__, 2))), $unused, $code);

    return $code === 0;
}

function install_package(bool $symlink): string
{
    $package = base_path(ConciergeFrontend::PACKAGE_PATH);
    expect(file_exists($package) || is_link($package))->toBeFalse();

    File::ensureDirectoryExists(dirname($package));

    if ($symlink) {
        link_package($package) || test()->markTestSkipped('This account cannot create a symlink or junction.');
    } else {
        File::ensureDirectoryExists($package.'/resources/js/pages');
        File::copy(dirname(__DIR__, 2).'/resources/js/pages/MembersPage.vue', $package.'/resources/js/pages/MembersPage.vue');
    }
    ConciergeFrontend::register(base_path());

    return $package;
}

afterEach(function () {
    $package = base_path(ConciergeFrontend::PACKAGE_PATH);
    $isWindows = PHP_OS_FAMILY === 'Windows';

    // Never let deleteDirectory follow a link into this checkout: a Windows directory
    // link (symlink or junction) is removed with rmdir, a Unix one with unlink.
    if (is_link($package) || ($isWindows && realpath($package) === realpath(dirname(__DIR__, 2)))) {
        $isWindows ? rmdir($package) : unlink($package);
    } else {
        File::deleteDirectory($package);
    }
    @rmdir(dirname($package));

    // The stylesheet test writes a starter kit's CSS entry and Vite config into the skeleton.
    File::delete(base_path('vite.config.ts'));
    File::deleteDirectory(base_path('resources/css'));
});

it('boots without a vendor copy and reports the declarations mod cannot use', function () {
    $list = mod_list();

    // Wiring may name directories that will exist later, so only the pages are refused.
    expect($list['package_pages'])->toBe([])
        ->and($list['frontend_wiring'])->toHaveCount(1)
        ->and(collect($list['frontend_notices'])->pluck('code')->unique()->values()->all())
        ->toBe(['frontend_path_unavailable'])
        ->and(collect($list['frontend_notices'])->pluck('path')->implode("\n"))->toContain('vendor/tey/concierge/resources/js/pages');
});

it('declares its pages and the wiring a host needs to mod', function (bool $symlink) {
    install_package($symlink);
    $list = mod_list();

    expect($list['package_pages'])->toBe([['namespace' => 'concierge', 'path' => 'vendor/tey/concierge/resources/js/pages']])
        ->and($list['frontend_wiring'])->toHaveCount(1)
        ->and($list['frontend_wiring'][0])->toMatchArray(['alias' => '@tey/concierge', 'root' => 'vendor/tey/concierge/resources/js'])
        ->and($list['frontend_notices'])->toBe([])
        ->and(Mod::pageEntry('concierge::MembersPage', 'vue'))->toBe('vendor/tey/concierge/resources/js/pages/MembersPage.vue');
})->with(['symlinked path repository' => true, 'copied install' => false]);

it('plans the alias, TypeScript paths and Tailwind source for mod:install inertia', function (bool $symlink) {
    install_package($symlink);
    $output = new BufferedOutput;
    app(Kernel::class)->call('mod:install', ['stack' => 'inertia', '--dry-run' => true, '--json' => true, '--no-interaction' => true], $output);
    $plan = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
    $instructions = implode("\n", array_column($plan['warnings'], 'message'));

    // The skeleton has no Vite config, tsconfig paths or CSS entry, so mod spells the edits out.
    expect($instructions)->toContain("fileURLToPath(new URL('./vendor/tey/concierge/resources/js', import.meta.url)) to resolve.alias")
        ->and($instructions)->toContain('["./vendor/tey/concierge/resources/js/*"] to compilerOptions.paths')
        ->and($instructions)->toContain("@source '../../vendor/tey/concierge/resources/js';");
})->with(['symlinked path repository' => true, 'copied install' => false]);

it('previews the four Vue wrapper pages as a JSON plan without writing', function () {
    [$exit, $json] = run_scaffold(['--dry-run' => true, '--json' => true]);
    $plan = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and(array_keys($plan))->toBe(['command', 'group', 'name', 'files', 'inserts', 'warnings', 'would_write', 'dry_run', 'written', 'skipped'])
        ->and($plan['would_write'])->toBeTrue()
        ->and($plan['dry_run'])->toBeTrue()
        ->and($plan['written'])->toBe([])
        ->and($plan['skipped'])->toBe([])
        ->and($plan['warnings'])->toBe([])
        ->and(collect($plan['files'])->pluck('identity.name', 'path')->all())->toBe([
            'resources/js/pages/Concierge/Members.vue' => 'Concierge/Members',
            'resources/js/pages/Concierge/InviteMember.vue' => 'Concierge/InviteMember',
            'resources/js/pages/Concierge/Member.vue' => 'Concierge/Member',
            'resources/js/pages/Concierge/AcceptInvitation.vue' => 'Concierge/AcceptInvitation',
        ])
        ->and(File::isDirectory(base_path(ScaffoldTestCase::PAGES)))->toBeFalse();
});

it('writes the four Vue wrapper pages from the package stubs', function () {
    [$exit, $output] = run_scaffold();

    $pages = base_path(ScaffoldTestCase::PAGES);

    expect($exit)->toBe(0)
        ->and($output)->toContain("Render it with Inertia::render('Concierge/Members')")
        ->and(collect(File::files($pages))->map->getFilename()->sort()->values()->all())
        ->toBe(['AcceptInvitation.vue', 'InviteMember.vue', 'Member.vue', 'Members.vue']);

    foreach (ConciergeScaffolds::PAGE_MEMBERS as ['name' => $name, 'stub' => $stub]) {
        expect(File::get($pages.'/'.basename($name).'.vue'))->toBe(File::get(ConciergeScaffolds::stubPath($stub)));
    }
});

it('refuses to overwrite pages the developer owns unless told to', function () {
    run_scaffold();
    $members = base_path(ScaffoldTestCase::PAGES.'/Members.vue');
    File::append($members, "<!-- edited by the app -->\n");

    [$exit, $output] = run_scaffold();
    expect($exit)->toBe(1)->and($output)->toContain('Nothing was written. Pass --skip-existing');

    [$exit] = run_scaffold(['--skip-existing' => true]);
    expect($exit)->toBe(0)->and(File::get($members))->toContain('edited by the app');

    [$exit] = run_scaffold(['--force' => true]);
    expect($exit)->toBe(0)->and(File::get($members))->not->toContain('edited by the app');
});

it('reports written and skipped paths as JSON without prompting, even on an interactive input', function () {
    $members = base_path(ScaffoldTestCase::PAGES.'/Members.vue');
    File::ensureDirectoryExists(dirname($members));
    File::put($members, '<!-- app-owned page -->');
    $input = new ArrayInput(['--json' => true]);
    $input->setInteractive(true);
    $output = new BufferedOutput;

    $exit = Artisan::all()['concierge:pages']->run($input, $output);
    $error = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
    expect($exit)->toBe(1)
        ->and($error['error'])->toContain('--skip-existing', '--force')
        ->and($error['written'])->toBe([])
        ->and(File::files(dirname($members)))->toHaveCount(1);

    [$exit, $json] = run_scaffold(['--json' => true, '--skip-existing' => true]);
    $result = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    expect($exit)->toBe(0)
        ->and(array_keys($result))->toBe(['command', 'group', 'name', 'files', 'inserts', 'warnings', 'would_write', 'dry_run', 'written', 'skipped'])
        ->and($result['dry_run'])->toBeFalse()
        ->and($result['skipped'])->toBe(['resources/js/pages/Concierge/Members.vue'])
        ->and($result['written'])->toBe([
            'resources/js/pages/Concierge/InviteMember.vue',
            'resources/js/pages/Concierge/Member.vue',
            'resources/js/pages/Concierge/AcceptInvitation.vue',
        ])
        ->and(File::get($members))->toBe('<!-- app-owned page -->');

    [$exit, $json] = run_scaffold(['--json' => true, '--skip-existing' => true]);
    $result = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    expect($exit)->toBe(0)
        ->and($result['written'])->toBe([])
        ->and($result['skipped'])->toBe([
            'resources/js/pages/Concierge/Members.vue',
            'resources/js/pages/Concierge/InviteMember.vue',
            'resources/js/pages/Concierge/Member.vue',
            'resources/js/pages/Concierge/AcceptInvitation.vue',
        ]);

    [$exit, $json] = run_scaffold(['--json' => true, '--force' => true, '--skip-existing' => true]);
    $result = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    expect($exit)->toBe(0)
        ->and($result['skipped'])->toBe([])
        ->and($result['written'])->toBe([
            'resources/js/pages/Concierge/Members.vue',
            'resources/js/pages/Concierge/InviteMember.vue',
            'resources/js/pages/Concierge/Member.vue',
            'resources/js/pages/Concierge/AcceptInvitation.vue',
        ])
        ->and(File::get($members))->toBe(File::get(ConciergeScaffolds::stubPath('concierge-members')));
});

it('needs an Inertia app', function () {
    File::put(base_path('package.json'), json_encode(['devDependencies' => ['vue' => '^3.5']]));

    [$exit, $json] = run_scaffold(['--dry-run' => true, '--json' => true]);
    $plan = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and(json_decode($json, flags: JSON_THROW_ON_ERROR)->warnings[0]->data)->toBeInstanceOf(stdClass::class)
        ->and($plan['would_write'])->toBeFalse()
        ->and($plan['files'])->toBe([])
        ->and($plan['warnings'][0]['code'])->toBe('generation_refused')
        ->and($plan['warnings'][0]['message'])->toContain('No Inertia app found');
});

it('runs the same recipe under mod\'s own name', function () {
    $output = new BufferedOutput;
    $exit = app(Kernel::class)->call('mod:'.ConciergeScaffolds::id(), ['name' => 'Concierge', '--dry-run' => true, '--json' => true, '--no-interaction' => true], $output);

    expect($exit)->toBe(0)
        ->and(json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR)['files'])->toHaveCount(4);
});

it('plans the stylesheet import for mod:install inertia and reports it wired once applied', function (bool $symlink) {
    install_package($symlink);
    $css = base_path('resources/css/app.css');
    File::ensureDirectoryExists(dirname($css));
    File::ensureDirectoryExists(base_path('resources/js'));
    File::put($css, "@import 'tailwindcss';\n");
    File::put(base_path('resources/js/app.ts'), <<<'TS'
        import { createInertiaApp } from '@inertiajs/vue3';
        import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

        createInertiaApp({
            resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<import('vue').DefineComponent>('./pages/**/*.vue')),
        });
        TS);
    File::put(base_path('vite.config.ts'), <<<'TS'
        import { defineConfig } from 'vite';
        import laravel from 'laravel-vite-plugin';

        export default defineConfig({
            plugins: [laravel({ input: ['resources/css/app.css', 'resources/js/app.ts'], refresh: true })],
        });

        TS);
    File::put(base_path('tsconfig.json'), "{\n    \"compilerOptions\": {\n        \"paths\": {\n            \"@/*\": [\"./resources/js/*\"]\n        }\n    },\n    \"include\": [\"resources/js/**/*\"]\n}\n");
    $import = "@import '../../vendor/tey/concierge/resources/css/concierge.css';";

    $install = function (array $options = []): array {
        $output = new BufferedOutput;
        $exit = app(Kernel::class)->call('mod:install', ['stack' => 'inertia', '--no-interaction' => true] + $options, $output);

        return [$exit, $output->fetch()];
    };

    [, $preview] = $install(['--dry-run' => true, '--json' => true]);
    $plan = json_decode($preview, true, flags: JSON_THROW_ON_ERROR);
    expect(collect($plan['files'])->firstWhere('path', 'resources/css/app.css')['identity']['after'])->toContain($import)
        ->and(File::get($css))->not->toContain($import);

    [$exit] = $install();
    expect($exit)->toBe(0)->and(substr_count(File::get($css), $import))->toBe(1);

    [$exit, $output] = $install();
    expect($exit)->toBe(0)
        ->and($output)->toContain('Already wired.')
        ->and(substr_count(File::get($css), $import))->toBe(1);
})->with(['symlinked path repository' => true, 'copied install' => false]);
