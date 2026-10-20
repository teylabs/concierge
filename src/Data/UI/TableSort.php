<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

/** The sort in force, from `?sort=name` (ascending) or `?sort=-name` (descending). */
#[TypeScript]
readonly class TableSort extends Props
{
    public function __construct(
        public string $column,
        #[LiteralTypeScriptType("'asc' | 'desc'")]
        public string $direction,
    ) {}
}
