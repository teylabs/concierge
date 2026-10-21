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
        /** @var list<RowAction> what the viewer may do to this member, decided by the grant rules */
        public array $actions = [],
        /** True when this member accepted an invitation sent to another email (invitations.match_email false). */
        public bool $acceptedWithOtherEmail = false,
        /** The invited email in that case, so a host can show "accepted by {user.email}". */
        public ?string $invitedEmail = null,
    ) {}
}
