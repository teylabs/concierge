<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class InviteUserPayload extends Props
{
    public function __construct(
        public string $email,
        /** @var list<string> */
        public array $roles,
    ) {}
}
