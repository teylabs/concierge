<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

/** Where the table is in its rows: `from` and `to` are null when there are none. */
#[TypeScript]
readonly class TablePagination extends Props
{
    public function __construct(
        public int $page,
        public int $perPage,
        public int $total,
        public ?int $from,
        public ?int $to,
        public int $lastPage,
    ) {}
}
