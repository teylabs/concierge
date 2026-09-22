<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;

class InvitationLinks extends Data
{
    public function __construct(
        public ?string $resend,
        public ?string $revoke,
    ) {}
}
