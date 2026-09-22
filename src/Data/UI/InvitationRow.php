<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;

class InvitationRow extends Data
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
    ) {}
}
