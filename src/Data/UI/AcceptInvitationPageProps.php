<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class AcceptInvitationPageProps extends Props
{
    public function __construct(
        #[LiteralTypeScriptType("'ready' | 'sign_in_required' | 'register_required' | 'wrong_account' | 'unverified' | 'expired' | 'revoked' | 'invalid' | 'accepted' | 'already_member' | 'suspended' | 'authority_lapsed' | 'workspace_unavailable' | 'grants_changed'")]
        public string $state,
        public ?WorkspaceSummary $workspace,
        public ?string $email,
        /** @var list<RoleBadge> */
        public array $roles,
        public AcceptInvitationMetadata $metadata,
        /** The signed-in user's email, named on the page when it is not the invited one (wrong_account). */
        public ?string $signedInAs = null,
    ) {}
}
