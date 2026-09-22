<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;

class AcceptInvitationMetadata extends Data
{
    public function __construct(
        public AcceptInvitationLinks $links,
    ) {}
}
