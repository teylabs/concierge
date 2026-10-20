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
        /** The active tab: `members` or `invitations`. */
        public string $tab = 'members',
        /** @var list<MembersTab> empty when the host does not use tabs */
        public array $tabs = [],
        /** The active tab's rows as a server-driven table; null when the host builds the page itself. */
        public ?MembersTable $table = null,
    ) {}
}
