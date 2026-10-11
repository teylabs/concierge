<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
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

/**
 * The provider declares the pages and wiring only for a copy of the package inside the
 * app, where Composer puts it. The skeleton has none (and its vendor/ is Testbench's
 * symlink to the real one), so the tests register a copy under packages/ themselves.
 */
function install_package_copy(): string
{
    $package = base_path('packages/concierge');
    File::ensureDirectoryExists($package.'/resources/js/pages');
    File::copy(dirname(__DIR__, 2).'/resources/js/pages/MembersPage.vue', $package.'/resources/js/pages/MembersPage.vue');
    ConciergeFrontend::register(base_path(), $package);

    return $package;
}

afterEach(fn () => File::deleteDirectory(base_path('packages')));

it('declares its pages and the wiring a host needs to mod', function () {
    install_package_copy();
    $output = new BufferedOutput;
    app(Kernel::class)->call('mod:list', ['--json' => true], $output);
    $list = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

    expect($list['package_pages'])->toBe([['namespace' => 'concierge', 'path' => 'packages/concierge/resources/js/pages']])
        ->and($list['frontend_wiring'])->toHaveCount(1)
        ->and($list['frontend_wiring'][0])->toMatchArray(['alias' => '@tey/concierge', 'root' => 'packages/concierge/resources/js'])
        ->and(Mod::pageEntry('concierge::MembersPage', 'vue'))->toBe('packages/concierge/resources/js/pages/MembersPage.vue');
});

it('plans the alias, TypeScript paths and Tailwind source for mod:install inertia', function () {
    install_package_copy();
    $output = new BufferedOutput;
    app(Kernel::class)->call('mod:install', ['stack' => 'inertia', '--dry-run' => true, '--json' => true, '--no-interaction' => true], $output);
    $plan = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
    $instructions = implode("\n", array_column($plan['warnings'], 'message'));

    // The skeleton has no Vite config, tsconfig paths or CSS entry, so mod spells the edits out.
    expect($instructions)->toContain("fileURLToPath(new URL('./packages/concierge/resources/js', import.meta.url)) to resolve.alias")
        ->and($instructions)->toContain('["./packages/concierge/resources/js/*"] to compilerOptions.paths')
        ->and($instructions)->toContain("@source '../../packages/concierge/resources/js';");
});

it('declares nothing for a package outside the app, including through a symlink', function () {
    expect(ConciergeFrontend::installedInside(base_path(), dirname(__DIR__, 2)))->toBeNull();

    $package = install_package_copy();
    $link = base_path('packages/linked');
    symlink(dirname(__DIR__, 2), $link);

    expect(ConciergeFrontend::installedInside(base_path(), $package))->toBe('packages/concierge')
        ->and(ConciergeFrontend::installedInside(base_path(), $link))->toBeNull()
        ->and(ConciergeFrontend::installedInside(base_path(), base_path('packages/missing')))->toBeNull();
});

it('previews the four Vue wrapper pages as a JSON plan without writing', function () {
    [$exit, $json] = run_scaffold(['--dry-run' => true, '--json' => true]);
    $plan = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($plan['would_write'])->toBeTrue()
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

it('needs an Inertia app', function () {
    File::put(base_path('package.json'), json_encode(['devDependencies' => ['vue' => '^3.5']]));

    [$exit, $json] = run_scaffold(['--dry-run' => true, '--json' => true]);
    $plan = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    expect($plan['would_write'])->toBeFalse()
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
