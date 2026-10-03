<?php

namespace Tey\Concierge\Support;

use SensitiveParameter;
use Tey\Concierge\Models\TenantInvitation;

final readonly class IssuedTenantInvitation
{
    public function __construct(
        public TenantInvitation $invitation,
        #[SensitiveParameter] public string $token,
    ) {}
}
