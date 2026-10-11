<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class RoleBadge extends Props
{
    public function __construct(
        public string $value,
        public string $label,
        public string $variant,
    ) {}
}
