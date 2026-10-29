<?php

namespace Tey\Concierge\Boost;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

/** @internal concierge:install --dry-run --json, read in-process for the tools. */
final class InstallPlan
{
    /**
     * @param  array<string, string>  $options
     * @return array<string, mixed>
     */
    public static function read(array $options = []): array
    {
        $output = new BufferedOutput;
        Artisan::call('concierge:install', ['--dry-run' => true, '--json' => true, '--no-interaction' => true] + $options, $output);

        return (array) json_decode($output->fetch(), true);
    }
}
