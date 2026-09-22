<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;

class InviteMemberPageProps extends Data
{
    public function __construct(
        public WorkspaceSummary $workspace,
        public InviteMemberPayload $payload,
        public InviteMemberMetadata $metadata,
    ) {}
}
