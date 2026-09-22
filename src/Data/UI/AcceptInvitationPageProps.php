<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

class AcceptInvitationPageProps extends Data
{
    public function __construct(
        #[LiteralTypeScriptType("'ready' | 'sign_in_required' | 'register_required' | 'wrong_account' | 'unverified' | 'expired' | 'revoked' | 'invalid' | 'accepted' | 'already_member' | 'suspended' | 'authority_lapsed' | 'workspace_unavailable' | 'grants_changed'")]
        public string $state,
        public ?WorkspaceSummary $workspace,
        public ?string $email,
        /** @var list<RoleBadge> */
        public array $roles,
        public AcceptInvitationMetadata $metadata,
    ) {}
}
