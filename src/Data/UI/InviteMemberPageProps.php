<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class InviteMemberPageProps extends Props
{
    public function __construct(
        public WorkspaceSummary $workspace,
        public InviteMemberPayload $payload,
        public InviteMemberMetadata $metadata,
    ) {}
}
