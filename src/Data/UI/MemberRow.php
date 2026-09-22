<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

class MemberRow extends Data
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
