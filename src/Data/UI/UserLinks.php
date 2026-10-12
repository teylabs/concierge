<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class UserLinks extends Props
{
    public function __construct(
        public ?string $show,
        public ?string $updateProfile,
        public ?string $updateGrants,
        public ?string $remove,
        public ?string $deactivate,
        public ?string $restore,
        public ?string $forcePasswordReset,
    ) {}
}
