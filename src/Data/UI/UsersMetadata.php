<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class UsersMetadata extends Props
{
    public function __construct(
        public string $refreshUrl,
        public string $search,
        public ?string $role,
        #[LiteralTypeScriptType("'active' | 'deactivated' | 'all' | null")]
        /** Null shows active users only, like 'active'; 'all' adds the deactivated. */
        public ?string $status,
        /** @var list<RoleOption> */
        public array $roleOptions,
        /** @var list<UserColumn> */
        public array $columns,
        public UsersLinks $links,
        public UsersAbilities $abilities,
    ) {}
}
