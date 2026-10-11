<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class RoleOption extends Props
{
    public function __construct(
        public string $value,
        public string $label,
        public ?string $description,
    ) {}
}
