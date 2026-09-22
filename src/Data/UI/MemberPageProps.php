<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;

class MemberPageProps extends Data
{
    public function __construct(
        public WorkspaceSummary $workspace,
        public MemberRow $member,
        public ?MemberGrants $grants,
        public MemberPageMetadata $metadata,
    ) {}
}
