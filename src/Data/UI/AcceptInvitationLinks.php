<?php

namespace Tey\Concierge\Data\UI;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Tey\Concierge\Data\Props;

#[TypeScript]
readonly class AcceptInvitationLinks extends Props
{
    public function __construct(
        public ?string $accept,
        public ?string $login,
        public ?string $register,
        public ?string $logout,
        public ?string $continue,
        /** POST: InvitationHandoff::switchAccount signs out and keeps the invitation for the next sign-in. */
        public ?string $switchAccount = null,
        /** Where Cancel goes on the wrong_account page. */
        public ?string $cancel = null,
    ) {}
}
