<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

/**
 * One thing the viewer may do to a row, already decided by the grant rules: a UI renders
 * exactly the actions it receives and shows nothing disabled for lack of permission.
 */
#[TypeScript]
readonly class RowAction extends Props
{
    public function __construct(
        public string $key,
        public string $label,
        public string $url,
        public string $method,
        public bool $destructive = false,
        public ?RowActionConfirm $confirm = null,
    ) {}
}
