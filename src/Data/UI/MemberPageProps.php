<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class MemberPageProps extends Props
{
    public function __construct(
        public WorkspaceSummary $workspace,
        public MemberRow $member,
        public ?MemberGrants $grants,
        public MemberPageMetadata $metadata,
    ) {}
}
