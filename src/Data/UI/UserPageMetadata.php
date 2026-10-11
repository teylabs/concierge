<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class UserPageMetadata extends Props
{
    public function __construct(
        public UserPageLinks $links,
    ) {}
}
