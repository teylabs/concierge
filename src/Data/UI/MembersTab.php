<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

/** A tab of the members index. The count is the server's and follows the active search, never the other filters. */
#[TypeScript]
readonly class MembersTab extends Props
{
    public function __construct(
        public string $key,
        public string $label,
        public string $url,
        public int $count,
        public bool $current,
    ) {}
}
