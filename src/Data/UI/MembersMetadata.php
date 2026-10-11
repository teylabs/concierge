<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class MembersMetadata extends Props
{
    public function __construct(
        public string $refreshUrl,
        public string $search,
        public ?string $status,
        public MembersLinks $links,
        public MembersAbilities $abilities,
    ) {}
}
