<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;

class AcceptInvitationLinks extends Data
{
    public function __construct(
        public ?string $accept,
        public ?string $login,
        public ?string $register,
        public ?string $logout,
        public ?string $continue,
    ) {}
}
