<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class InviteMemberLinks extends Props
{
    public function __construct(
        public string $cancel,
    ) {}
}
