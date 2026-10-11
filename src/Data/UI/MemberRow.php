<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class MemberRow extends Props
{
    public function __construct(
        public string $id,
        public UserSummary $user,
        #[LiteralTypeScriptType("'active' | 'suspended' | 'removed'")]
        public string $status,
        public bool $isOwner,
        public bool $isSelf,
        /** @var list<RoleBadge> */
        public array $roles,
        public ?string $joinedAt,
        public bool $hasActions,
        public MemberLinks $links,
    ) {}
}
