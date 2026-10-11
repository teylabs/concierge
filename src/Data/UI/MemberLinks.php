<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class MemberLinks extends Props
{
    public function __construct(
        public ?string $show,
        public ?string $updateGrants,
        public ?string $suspend,
        public ?string $reinstate,
        public ?string $remove,
        public ?string $transferOwnership,
    ) {}
}
