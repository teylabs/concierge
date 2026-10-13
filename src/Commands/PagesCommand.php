<?php

namespace Tey\Concierge\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Output\BufferedOutput;
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
            '--no-interaction' => true,
        ]);
        $options = ['name' => $this->argument('name')] + $options;
        $command = 'mod:'.ConciergeScaffolds::id();
        $output = new BufferedOutput;

        // Mod only emits JSON for previews, and a refused preview can still exit 0.
        $exit = app(Kernel::class)->call($command, $options + ['--dry-run' => true, '--json' => true], $output);
        $plan = json_decode($output->fetch(), flags: JSON_THROW_ON_ERROR);
        $dryRun = (bool) $this->option('dry-run');
        $skipped = [];
        $written = [];

        foreach ($plan->files as $file) {
            if ($file->exists && ($file->existing === 'keep' || ($this->option('skip-existing') && ! $this->option('force')))) {
                $skipped[] = $file->path;
            } elseif (! $dryRun && $plan->would_write) {
                $written[] = $file->path;
            }
        }

        $result = (array) $plan + ['dry_run' => $dryRun, 'written' => $written, 'skipped' => $skipped];

        if ($exit !== self::SUCCESS || ! $plan->would_write) {
            $message = implode("\n", array_column($plan->warnings, 'message'))
                ?: 'Pages already exist. Nothing was written. Pass --skip-existing to keep them, or --force to overwrite them.';
            $result['error'] = $message;

            if ($this->option('json')) {
                $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        if (! $this->option('json')) {
            return $this->call($command, $options + ($dryRun ? ['--dry-run' => true] : []));
        }

        if (! $dryRun) {
            $exit = app(Kernel::class)->call($command, $options, $output);

            if ($exit !== self::SUCCESS) {
                $this->line((string) json_encode(['error' => trim($output->fetch())], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::FAILURE;
            }
        }

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
