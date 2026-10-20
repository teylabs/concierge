<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

/** The words of the confirmation a destructive row action asks for. */
#[TypeScript]
readonly class RowActionConfirm extends Props
{
    public function __construct(
        public string $title,
        public string $body,
        public string $confirmLabel,
    ) {}
}
