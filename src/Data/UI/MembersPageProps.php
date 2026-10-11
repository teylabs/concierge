<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class MembersPageProps extends Props
{
    public function __construct(
        public WorkspaceSummary $workspace,
        /** @var list<MemberRow> */
        public array $members,
        public Pagination $pagination,
        /** @var list<InvitationRow> */
        public array $invitations,
        public MembersMetadata $metadata,
    ) {}
}
