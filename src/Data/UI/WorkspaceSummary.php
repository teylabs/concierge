<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class WorkspaceSummary extends Props
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
