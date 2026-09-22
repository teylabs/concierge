<?php

namespace Tey\Concierge\Support;

use SensitiveParameter;
use Tey\Concierge\Models\Invitation;

final readonly class IssuedInvitation
{
    public function __construct(
        public Invitation $invitation,
        #[SensitiveParameter] public string $token,
    ) {}
}
