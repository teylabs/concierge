<?php

namespace Tey\Concierge\Commands;

use Illuminate\Console\Command;
use Tey\Concierge\Scaffolding\ConciergeScaffolds;

/**
 * Writes concierge's Inertia pages into the app. Mod does the work; mod names a
 * package's scaffold command mod:<recipe>, so this is the concierge:* name for it
 * until a package can name its own (teylabs/mod#95).
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

        return $this->call('mod:'.ConciergeScaffolds::PAGES, ['name' => $this->argument('name')] + $options);
    }
}
