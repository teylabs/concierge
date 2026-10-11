<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class UserColumn extends Props
{
    public function __construct(
        public string $key,
        public string $label,
    ) {}
}
