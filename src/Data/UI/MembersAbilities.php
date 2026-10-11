<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class MembersAbilities extends Props
{
    public function __construct(
        public bool $invite,
        public bool $manageMembers,
        public bool $transferOwnership,
    ) {}
}
