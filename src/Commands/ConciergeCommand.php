<?php

namespace Tey\Concierge\Commands;

use Illuminate\Console\Command;

class ConciergeCommand extends Command
{
    public $signature = 'concierge';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
