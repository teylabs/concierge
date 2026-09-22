<?php

namespace Tey\Concierge\Data\UI;

use Spatie\LaravelData\Data;

class InviteMemberPayload extends Data
{
    public function __construct(
        public string $email,
        /** @var list<string> */
        public array $roles,
    ) {}
}
