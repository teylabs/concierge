<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class InvitationRow extends Props
{
    public function __construct(
        public string $id,
        public string $email,
        /** @var list<RoleBadge> */
        public array $roles,
        public string $status,
        public string $expiresAt,
        public ?string $lastSentAt,
        public ?string $invitedBy,
        public bool $hasActions,
        public InvitationLinks $links,
        /** @var list<RowAction> what the viewer may do to this invitation, decided by the grant rules */
        public array $actions = [],
        /** The inviter's optional note, at most 500 characters. */
        public ?string $message = null,
    ) {}
}
