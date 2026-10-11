<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class UserPageProps extends Props
{
    public function __construct(
        public UserRow $user,
        public ?UserProfile $profile,
        public ?UserGrants $grants,
        public UserPageMetadata $metadata,
    ) {}
}
