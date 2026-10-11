<?php

namespace Tey\Concierge\Commands;

use Illuminate\Console\Command;
use Tey\Concierge\Scaffolding\ConciergeScaffolds;

/**
 * Writes concierge's Inertia pages into the app. Mod does the work under
 * mod:concierge/pages. Mod 0.6 lets a package name its scaffold command, but a
 * scaffold's name argument is required there, and this command's default
 * (`Concierge`) is what lets `php artisan concierge:pages` run bare. So the
 * concierge:* name stays a thin wrapper that supplies the default.
 */
class PagesCommand extends Command
{
    public $signature = 'concierge:pages
        {name=Concierge : The folder the pages go in, under the app\'s pages directory}
        {--in= : The module (or other layout group) the pages go in}
        {--force : Overwrite pages that already exist}
        {--skip-existing : Leave pages that already exist as they are}
        {--dry-run : Show what would be written, and write nothing}
        {--json : Print the plan or the result as JSON}';

    public $description = 'Write the Concierge pages into the app\'s own resources/js/pages, ready to edit.';

    public function handle(): int
    {
        $options = array_filter([
            '--in' => $this->option('in'),
            '--force' => $this->option('force'),
            '--skip-existing' => $this->option('skip-existing'),
            '--dry-run' => $this->option('dry-run'),
            '--json' => $this->option('json'),
            '--no-interaction' => ! $this->input->isInteractive(),
        ]);

        return $this->call('mod:'.ConciergeScaffolds::id(), ['name' => $this->argument('name')] + $options);
    }
}
