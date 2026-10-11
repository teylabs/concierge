<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class MemberGrantsPayload extends Props
{
    public function __construct(
        /** @var list<string> */
        public array $roles,
    ) {}
}
