<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class InviteMemberMetadata extends Props
{
    public function __construct(
        public string $formUrl,
        /** @var list<RoleOption> */
        public array $roleOptions,
        public InviteMemberLinks $links,
    ) {}
}
