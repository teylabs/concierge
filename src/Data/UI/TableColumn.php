<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

/** A column of a server-driven table, in display order. A sortable column is one the query accepts in `sort`. */
#[TypeScript]
readonly class TableColumn extends Props
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $sortable = false,
        public bool $hideable = false,
        public bool $hiddenByDefault = false,
        public ?string $align = null,
        public bool $srOnlyLabel = false,
    ) {}
}
