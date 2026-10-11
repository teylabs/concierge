<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;
use Tey\Concierge\Scaffolding\ConciergeScaffolds;
use Tey\Concierge\Tests\Scaffolding\ScaffoldTestCase;

/**
 * Mod picks the page extension from the app's stack, but a stub
 * variant a package registers is keyed by "<type>.<variant>" with no extension.
 * Concierge therefore registers its Vue variants only for a Vue app, and a React
 * app is told which stub to supply instead of receiving Vue code in a .tsx file
 * (teylabs/mod#90).
 */
it('plans .tsx pages in a React app and refuses without a React variant', function () {
    $output = new BufferedOutput;
    $exit = app(Kernel::class)->call('mod:'.ConciergeScaffolds::PAGES, ['name' => 'Concierge', '--no-interaction' => true, '--dry-run' => true, '--json' => true], $output);
    $plan = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($plan['would_write'])->toBeFalse()
        ->and(implode("\n", array_column($plan['warnings'], 'message')))->toContain('stubs/mod.page.concierge-members.tsx.stub')
        ->and(File::isDirectory(base_path(ScaffoldTestCase::PAGES)))->toBeFalse();
});
