<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class AcceptInvitationMetadata extends Props
{
    public function __construct(
        public AcceptInvitationLinks $links,
        /** Named on the page when the invitation has no workspace (app-wide mode). */
        public string $appName,
    ) {}
}
