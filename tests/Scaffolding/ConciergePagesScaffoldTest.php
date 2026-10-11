<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;
use Tey\Concierge\Scaffolding\ConciergeScaffolds;
use Tey\Concierge\Tests\Scaffolding\ScaffoldTestCase;

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
        ->and($list['stack'])->toBe(['inertia' => 'vue', 'typescript' => true, 'pages' => 'resources/js/pages'])
        ->and(collect($list['scaffolds']['items'])->firstWhere('name', ConciergeScaffolds::PAGES))
        ->toMatchArray(['command' => 'mod:concierge-pages', 'source' => 'package:tey/concierge'])
        ->and($list['scaffolds']['problems'])->toBe([]);
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
        ->and($plan['warnings'][0]['message'])->toContain('No Inertia app found in package.json');
});

it('runs the same recipe under mod\'s own name', function () {
    $output = new BufferedOutput;
    $exit = app(Kernel::class)->call('mod:'.ConciergeScaffolds::PAGES, ['name' => 'Concierge', '--dry-run' => true, '--json' => true, '--no-interaction' => true], $output);

    expect($exit)->toBe(0)
        ->and(json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR)['files'])->toHaveCount(4);
});
