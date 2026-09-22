<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;

class MembersPageProps extends Data
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
