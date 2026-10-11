<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;
use Tey\Concierge\Tests\Scaffolding\ModulesScaffoldTestCase;
use Tey\Mod\Facades\Mod;

/**
 * On the modules layout the pages land in the module the developer names, under
 * the module's page namespace. Mod registers that namespace with Inertia's
 * page finders and maps it to a Vite entry, so `Billing::Concierge/Members`
 * renders like any page the module owns.
 */
it('writes the pages into a module under its page namespace', function () {
    $output = new BufferedOutput;
    $exit = app(Kernel::class)->call('concierge:pages', ['name' => 'Billing:Concierge', '--no-interaction' => true], $output);

    expect($exit)->toBe(0)
        ->and($output->fetch())->toContain("Inertia::render('Billing::Concierge/Members')")
        ->and(collect(File::files(base_path(ModulesScaffoldTestCase::PAGES)))->map->getFilename()->sort()->values()->all())
        ->toBe(['AcceptInvitation.vue', 'InviteMember.vue', 'Member.vue', 'Members.vue'])
        ->and(Mod::pageEntry('Billing::Concierge/Members', 'vue'))
        ->toBe(ModulesScaffoldTestCase::PAGES.'/Members.vue');
});

it('takes the module with --in, as mod asks when it is missing', function () {
    $output = new BufferedOutput;
    $exit = app(Kernel::class)->call('concierge:pages', ['--no-interaction' => true, '--dry-run' => true, '--json' => true], $output);
    $plan = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($plan['would_write'])->toBeFalse()
        ->and($plan['warnings'][0]['code'])->toBe('missing_placement')
        ->and($plan['warnings'][0]['data']['dimension'])->toBe('module');

    $output = new BufferedOutput;
    $exit = app(Kernel::class)->call('concierge:pages', ['--in' => 'Billing', '--no-interaction' => true], $output);

    expect($exit)->toBe(0)
        ->and($output->fetch())->toContain("Inertia::render('Billing::Concierge/Members')")
        ->and(File::isFile(base_path(ModulesScaffoldTestCase::PAGES.'/Members.vue')))->toBeTrue();
});
